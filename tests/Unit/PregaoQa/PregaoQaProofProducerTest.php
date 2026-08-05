<?php

declare(strict_types=1);

namespace Tests\Unit\PregaoQa;

use App\Services\Pregao\PregaoEmitService;
use App\Services\Pregao\PregaoQaProof;
use App\Services\Pregao\PregaoQaRunService;
use App\Services\Pregao\PregaoQaStatusProducer;
use PDO;

use PHPUnit\Framework\TestCase;
use Redis;

final class PregaoQaProofProducerTest extends TestCase
{
    public function testTrustedProducerEmitsOnlyEvidenceBoundToManifestAndAccount(): void
    {
        $proof = new PregaoQaProof(str_repeat('p', 32));
        $observedAt = new \DateTimeImmutable();
        $startedAt = $observedAt->modify('-1 minute');
        $manifest = $proof->signManifest([
            'run_id' => '123e4567-e89b-42d3-a456-426614174000',
            'account_id' => 1335,
            'user_id' => 77,
            'created_at' => $startedAt->format(DATE_ATOM),
            'expires_at' => $observedAt->modify('+14 minutes')->format(DATE_ATOM),
        ]);
        $protocol = [
            'run_id' => $manifest['run_id'],
            'sequence' => 1,
            'step' => 'dashboard',
            'result' => 'running',
            'screenshot' => 'latest.png',
            'cursor' => ['x' => 10, 'y' => 20],
            'observed_at' => $observedAt->format(DATE_ATOM),
        ];

        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE pregao_events (id INTEGER PRIMARY KEY AUTOINCREMENT, account_id INTEGER, type TEXT, ts TEXT, payload TEXT, source TEXT)');
        $store = [
            PregaoQaRunService::stateKey($manifest['run_id']) => json_encode([
                'run_id' => $manifest['run_id'],
                'account_id' => 1335,
                'manifest_hash' => $manifest['manifest_hash'],
                'status' => 'running',
                'sequence' => 1,
                'step' => 'dashboard',
                'screenshot_url' => '/qa/frame/' . $manifest['run_id'],
                'cursor' => ['x' => 10, 'y' => 20],
                'observed_at' => $protocol['observed_at'],
            ], JSON_THROW_ON_ERROR),
            PregaoQaRunService::manifestKey($manifest['run_id']) => json_encode($manifest, JSON_THROW_ON_ERROR),
        ];
        $published = null;
        $redis = $this->createMock(Redis::class);
        $redis->method('get')->willReturnCallback(static function (string $key) use (&$store): string|false {
            return $store[$key] ?? false;
        });
        $redis->method('eval')->willReturnCallback(static function (string $lua, array $args, int $keyCount) use (&$store): int {
            if ($keyCount !== 3) {
                return 0;
            }
            $receipt = json_decode((string) $args[13], true, 512, JSON_THROW_ON_ERROR);
            $state = json_decode((string) $store[$args[0]], true, 512, JSON_THROW_ON_ERROR);
            $state['receipt_hash'] = $receipt['payload_hash'];
            $state['receipt_event_id'] = $receipt['event_id'];
            $store[$args[0]] = json_encode($state, JSON_THROW_ON_ERROR);
            $store[$args[1]] = json_encode($receipt, JSON_THROW_ON_ERROR);
            $store[$args[2]] = $receipt['run_id'];
            return 1;
        });
        $redis->method('publish')->willReturnCallback(static function (string $channel, string $json) use (&$published): int {
            $published = json_decode($json, true);
            return 1;
        });
        $redis->method('lPush')->willReturn(1);
        $redis->method('lTrim')->willReturn(true);
        $runs = new PregaoQaRunService($redis, $proof);

        $producer = new PregaoQaStatusProducer(new PregaoEmitService($db, $redis), $proof, $runs);
        $event = $producer->emit($manifest, $protocol);

        self::assertSame('qa.status', $event['type']);
        self::assertSame(1335, $event['account_id']);
        self::assertTrue($proof->verifyStatus($event['payload'], 1335));
        self::assertSame('/qa/live/' . $manifest['run_id'], $event['payload']['stream_url']);
        self::assertSame($manifest['created_at'], $event['payload']['started_at']);
        self::assertSame('running', $proof->projectStatus($event['payload'], 1335)['status']);
        self::assertSame($event, $published);

        $event['payload']['result'] = 'failed';
        self::assertFalse($proof->verifyStatus($event['payload'], 1335));
        self::assertFalse($proof->verifyStatus($published['payload'], 9999));
    }

    public function testProducerRejectsValidProtocolWithoutDurableState(): void
    {
        $proof = new PregaoQaProof(str_repeat('p', 32));
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $manifest = $proof->signManifest([
            'run_id' => '123e4567-e89b-42d3-a456-426614174000',
            'account_id' => 1335,
            'user_id' => 77,
            'created_at' => $now->modify('-1 minute')->format(DATE_ATOM),
            'expires_at' => $now->modify('+14 minutes')->format(DATE_ATOM),
        ]);
        $pdo = $this->createMock(PDO::class);
        $redis = $this->createMock(Redis::class);
        $redis->method('publish')->willReturn(1);
        $redis->method('lPush')->willReturn(1);
        $redis->method('lTrim')->willReturn(true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('progressão durável');
        (new PregaoQaStatusProducer(
            new PregaoEmitService($pdo, $redis),
            $proof,
            new PregaoQaRunService($redis, $proof)
        ))->emit($manifest, [
            'run_id' => $manifest['run_id'],
            'sequence' => 1,
            'step' => 'dashboard',
            'result' => 'running',
            'screenshot' => null,
            'cursor' => null,
            'observed_at' => $now->format(DATE_ATOM),
        ]);
    }

    public function testProducerConfirmsAuthoritativeReceiptAndIsIdempotentAfterCrashReplay(): void
    {
        $proof = new PregaoQaProof(str_repeat('p', 32));
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $manifest = $proof->signManifest([
            'run_id' => '123e4567-e89b-42d3-a456-426614174000',
            'account_id' => 1335,
            'user_id' => 77,
            'created_at' => $now->modify('-1 minute')->format(DATE_ATOM),
            'expires_at' => $now->modify('+14 minutes')->format(DATE_ATOM),
        ]);
        $protocol = [
            'run_id' => $manifest['run_id'],
            'sequence' => 1,
            'step' => 'dashboard',
            'result' => 'running',
            'screenshot' => null,
            'cursor' => null,
            'observed_at' => $now->format(DATE_ATOM),
        ];
        $store = [
            PregaoQaRunService::stateKey($manifest['run_id']) => json_encode([
                'run_id' => $manifest['run_id'],
                'account_id' => 1335,
                'manifest_hash' => $manifest['manifest_hash'],
                'status' => 'running',
                'sequence' => 1,
                'step' => 'dashboard',
                'screenshot_url' => null,
                'cursor' => null,
                'observed_at' => $protocol['observed_at'],
            ], JSON_THROW_ON_ERROR),
            PregaoQaRunService::manifestKey($manifest['run_id']) => json_encode($manifest, JSON_THROW_ON_ERROR),
        ];
        $redis = $this->createMock(Redis::class);
        $redis->method('get')->willReturnCallback(static function (string $key) use (&$store): string|false {
            return $store[$key] ?? false;
        });
        $redis->method('eval')->willReturnCallback(static function (string $lua, array $args, int $keyCount) use (&$store): int {
            if ($keyCount !== 3) {
                return 0;
            }
            $receipt = json_decode((string) $args[13], true, 512, JSON_THROW_ON_ERROR);
            $state = json_decode((string) $store[$args[0]], true, 512, JSON_THROW_ON_ERROR);
            $state['receipt_hash'] = $receipt['payload_hash'];
            $state['receipt_event_id'] = $receipt['event_id'];
            $store[$args[0]] = json_encode($state, JSON_THROW_ON_ERROR);
            $store[$args[1]] = json_encode($receipt, JSON_THROW_ON_ERROR);
            $store[$args[2]] = $receipt['run_id'];
            return 1;
        });
        $publishCount = 0;
        $redis->method('publish')->willReturnCallback(static function () use (&$publishCount): int {
            $publishCount++;
            return 1;
        });
        $redis->method('lPush')->willReturn(1);
        $redis->method('lTrim')->willReturn(true);
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE pregao_events (id INTEGER PRIMARY KEY AUTOINCREMENT, account_id INTEGER, type TEXT, ts TEXT, payload TEXT, source TEXT)');
        $runs = new PregaoQaRunService($redis, $proof);
        $emitter = new PregaoEmitService($db, $redis);
        $producer = new PregaoQaStatusProducer($emitter, $proof, $runs);
        $signedStatus = $proof->signStatus([
            'running' => true,
            'suite' => 'pregao-live',
            'test' => 'dashboard',
            'result' => 'running',
            'video_url' => null,
            'stream_url' => null,
            'run_id' => $manifest['run_id'],
            'sequence' => 1,
            'step' => 'dashboard',
            'screenshot_url' => null,
            'observed_at' => $protocol['observed_at'],
            'started_at' => $manifest['created_at'],
            'manifest_hash' => $manifest['manifest_hash'],
        ], 1335);
        // Simula crash depois de DB/publish e antes de confirmEvidence().
        $beforeCrash = $emitter->emitTrustedQaStatusWithReceipt($signedStatus, 1335, $proof);

        $first = $producer->emit($manifest, $protocol);
        $second = $producer->emit($manifest, $protocol);

        self::assertTrue($runs->isStatusAuthoritative($first['payload'], 1335));
        self::assertSame($first, $second);
        self::assertSame(1, (int) $db->query("SELECT COUNT(*) FROM pregao_events WHERE type = 'qa.status'")->fetchColumn());
        self::assertSame(1, $publishCount);
        $receipt = json_decode(
            $store[PregaoQaRunService::receiptKey($manifest['run_id'])],
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame($beforeCrash['event_id'], $receipt['event_id']);
        self::assertSame(PregaoQaRunService::statusHash($first['payload']), $receipt['payload_hash']);
    }

    public function testGenericEmitRemainsBlockedEvenForWellFormedQaPayload(): void
    {
        $service = new PregaoEmitService($this->createMock(PDO::class), $this->createMock(Redis::class));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('produtor confiável');
        $service->emit('qa.status', [
            'running' => false,
            'suite' => 'pregao-live',
            'test' => 'dashboard',
            'result' => 'passed',
        ], 1335);
    }

    public function testWorkerWiresRunServiceIntoProducerAndCrashRecovery(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/bin/pregao-qa-worker.php');
        self::assertIsString($source);
        self::assertStringContainsString(
            'new PregaoQaStatusProducer(new PregaoEmitService(null, $redis), $proof, $runs)',
            $source
        );
        self::assertStringContainsString('$runs->recoverPending(', $source);
        self::assertStringContainsString('$producer->repairEvidence($manifest, $state)', $source);
        self::assertStringNotContainsString('$runs->recoverPending();', $source);
    }

    public function testProducerRejectsObservationAfterManifestExpiryWithinClockSkew(): void
    {
        $proof = new PregaoQaProof(str_repeat('p', 32));
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $manifest = $proof->signManifest([
            'run_id' => '123e4567-e89b-42d3-a456-426614174000',
            'account_id' => 1335,
            'user_id' => 77,
            'created_at' => $now->modify('-1 minute')->format(DATE_ATOM),
            'expires_at' => $now->modify('+15 seconds')->format(DATE_ATOM),
        ]);
        $protocol = [
            'run_id' => $manifest['run_id'],
            'sequence' => 1,
            'step' => 'dashboard',
            'result' => 'running',
            'screenshot' => null,
            'cursor' => null,
            'observed_at' => $now->modify('+30 seconds')->format(DATE_ATOM),
        ];
        $emitter = new PregaoEmitService($this->createMock(PDO::class), $this->createMock(Redis::class));

        $this->expectException(\InvalidArgumentException::class);
        (new PregaoQaStatusProducer(
            $emitter,
            $proof,
            new PregaoQaRunService($this->createMock(Redis::class), $proof)
        ))->emit($manifest, $protocol);
    }
}
