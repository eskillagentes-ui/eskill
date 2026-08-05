<?php

declare(strict_types=1);

namespace Tests\Unit\PregaoQa;

use App\Services\Pregao\PregaoQaProof;
use App\Services\Pregao\PregaoQaRunService;
use App\Services\Pregao\PregaoSnapshotService;
use App\Services\Pregao\PregaoStreamService;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Redis;

final class PregaoQaSnapshotStreamTest extends TestCase
{
    public function testSnapshotFailsClosedWithoutAuthoritativeReceipt(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE pregao_events (id INTEGER PRIMARY KEY AUTOINCREMENT, account_id INTEGER, type TEXT, ts TEXT, payload TEXT, source TEXT)');
        $proof = new PregaoQaProof(str_repeat('s', 32));
        $observedAt = new \DateTimeImmutable();
        $startedAt = $observedAt->modify('-1 minute');
        $manifest = $proof->signManifest([
            'run_id' => '123e4567-e89b-42d3-a456-426614174000',
            'account_id' => 1335,
            'user_id' => 77,
            'created_at' => $startedAt->format(DATE_ATOM),
            'expires_at' => $observedAt->modify('+14 minutes')->format(DATE_ATOM),
        ]);
        $payload = $proof->signStatus([
            'running' => false,
            'suite' => 'pregao-live',
            'test' => 'dashboard',
            'result' => 'passed',
            'video_url' => null,
            'stream_url' => '/qa/live/' . $manifest['run_id'],
            'run_id' => $manifest['run_id'],
            'sequence' => 1,
            'step' => 'dashboard',
            'screenshot_url' => '/qa/frame/' . $manifest['run_id'],
            'observed_at' => $observedAt->format(DATE_ATOM),
            'started_at' => $manifest['created_at'],
            'manifest_hash' => $manifest['manifest_hash'],
        ], 1335);
        $db->prepare('INSERT INTO pregao_events (account_id,type,ts,payload,source) VALUES (?,?,?,?,?)')
            ->execute([1335, 'qa.status', '2026-08-05 12:01:00', json_encode($payload), 'live']);

        $service = new PregaoSnapshotService($db, null, ['seed_enabled' => false], null, null, $proof);
        $method = new ReflectionMethod($service, 'loadLatestQa');
        $method->setAccessible(true);
        $qa = $method->invoke($service, 1335);
        self::assertFalse($qa['executed']);
        self::assertNull($qa['result']);

        $payload['signature'] = str_repeat('0', 64);
        $db->prepare('INSERT INTO pregao_events (account_id,type,ts,payload,source) VALUES (?,?,?,?,?)')
            ->execute([1335, 'qa.status', '2026-08-05 12:02:00', json_encode($payload), 'live']);
        $closed = $method->invoke($service, 1335);
        self::assertFalse($closed['executed']);
        self::assertNull($closed['result']);
    }

    public function testStreamAndWebSocketValidationFailClosedForQaProof(): void
    {
        $proof = new PregaoQaProof(str_repeat('s', 32));
        $payload = $proof->signStatus([
            'running' => true,
            'suite' => 'pregao-live',
            'test' => 'dashboard',
            'result' => 'running',
            'video_url' => null,
            'stream_url' => null,
            'run_id' => '123e4567-e89b-42d3-a456-426614174000',
            'sequence' => 1,
            'step' => 'dashboard',
            'screenshot_url' => null,
            'observed_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'started_at' => (new \DateTimeImmutable('-1 second'))->format(DATE_ATOM),
            'manifest_hash' => str_repeat('a', 64),
        ], 1335);
        $event = [
            'v' => PregaoStreamService::eventVersion(),
            'type' => 'qa.status',
            'ts' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'payload' => $payload,
            'source' => 'live',
            'account_id' => 1335,
        ];
        self::assertFalse(PregaoStreamService::isEventAllowedForAccount($event, 1335, $proof));
        $event['payload']['sequence'] = 2;
        self::assertFalse(PregaoStreamService::isEventAllowedForAccount($event, 1335, $proof));
        self::assertFalse(PregaoStreamService::isEventAllowedForAccount($event, 9999, $proof));
    }

    public function testSnapshotUsesExactTerminalReceiptForTwentyFourHourHistoryInsteadOfReinsertedFrame(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE pregao_events (id INTEGER PRIMARY KEY AUTOINCREMENT, account_id INTEGER, type TEXT, ts TEXT, payload TEXT, source TEXT)');
        $proof = new PregaoQaProof(str_repeat('s', 32));
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $observedAt = $now->modify('-23 hours');
        $manifest = $proof->signManifest([
            'run_id' => '123e4567-e89b-42d3-a456-426614174000',
            'account_id' => 1335,
            'user_id' => 77,
            'created_at' => $observedAt->modify('-10 minutes')->format(DATE_ATOM),
            'expires_at' => $observedAt->modify('+5 minutes')->format(DATE_ATOM),
        ]);
        $terminal = $proof->signStatus(
            $this->status($manifest, 5, 'console_http', 'passed', $observedAt),
            1335
        );
        $capturedOld = $proof->signStatus(
            $this->status($manifest, 1, 'dashboard', 'running', $observedAt->modify('-9 minutes')),
            1335
        );
        $insert = $db->prepare('INSERT INTO pregao_events (account_id,type,ts,payload,source) VALUES (?,?,?,?,?)');
        $insert->execute([1335, 'qa.status', '2026-08-05 12:01:00', json_encode($terminal), 'live']);
        $authoritativeId = (int) $db->lastInsertId();
        $insert->execute([1335, 'qa.status', '2026-08-05 12:02:00', json_encode($capturedOld), 'live']);
        $receipt = [
            'run_id' => $manifest['run_id'],
            'account_id' => 1335,
            'manifest_hash' => $manifest['manifest_hash'],
            'manifest_expires_at' => $manifest['expires_at'],
            'sequence' => 5,
            'step' => 'console_http',
            'result' => 'passed',
            'observed_at' => $terminal['observed_at'],
            'payload_hash' => PregaoQaRunService::statusHash($terminal),
            'event_id' => $authoritativeId,
            'event_ts' => $now->format(DATE_ATOM),
            'status' => $terminal,
        ];
        $state = [
            'run_id' => $manifest['run_id'],
            'account_id' => 1335,
            'manifest_hash' => $manifest['manifest_hash'],
            'status' => 'passed',
            'sequence' => 5,
            'step' => 'console_http',
            'observed_at' => $terminal['observed_at'],
            'receipt_hash' => $receipt['payload_hash'],
            'receipt_event_id' => $authoritativeId,
        ];
        $redis = $this->createMock(Redis::class);
        $redis->method('get')->willReturnCallback(static function (string $key) use ($manifest, $receipt, $state, $terminal, $authoritativeId): string|false {
            return match ($key) {
                PregaoQaRunService::latestReceiptKey(1335) => json_encode([
                    'run_id' => $manifest['run_id'],
                    'sequence' => 5,
                    'event_id' => $authoritativeId,
                    'payload_hash' => $receipt['payload_hash'],
                    'status_signature' => $terminal['signature'],
                ], JSON_THROW_ON_ERROR),
                PregaoQaRunService::receiptKey($manifest['run_id'], 5) => json_encode($receipt, JSON_THROW_ON_ERROR),
                PregaoQaRunService::stateKey($manifest['run_id']) => json_encode($state, JSON_THROW_ON_ERROR),
                default => false,
            };
        });
        $runs = new PregaoQaRunService($redis, $proof);
        $service = new PregaoSnapshotService($db, null, ['seed_enabled' => false], null, null, $proof, $runs);
        $method = new ReflectionMethod($service, 'loadLatestQa');
        $method->setAccessible(true);

        $qa = $method->invoke($service, 1335);
        self::assertSame('passed', $qa['result']);
        self::assertSame(5, $qa['sequence']);
    }

    public function testStreamRejectsValidHmacWithoutMatchingAuthoritativeReceipt(): void
    {
        $proof = new PregaoQaProof(str_repeat('s', 32));
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $manifest = $proof->signManifest([
            'run_id' => '123e4567-e89b-42d3-a456-426614174000',
            'account_id' => 1335,
            'user_id' => 77,
            'created_at' => $now->modify('-1 minute')->format(DATE_ATOM),
            'expires_at' => $now->modify('+14 minutes')->format(DATE_ATOM),
        ]);
        $payload = $proof->signStatus($this->status($manifest, 1, 'dashboard', 'running', $now), 1335);
        $event = [
            'v' => PregaoStreamService::eventVersion(),
            'type' => 'qa.status',
            'ts' => $now->format(DATE_ATOM),
            'payload' => $payload,
            'source' => 'live',
            'account_id' => 1335,
        ];
        $redis = $this->createMock(Redis::class);
        $redis->method('get')->willReturn(false);
        $runs = new PregaoQaRunService($redis, $proof);

        self::assertFalse(PregaoStreamService::isEventAllowedForAccount($event, 1335, $proof, $runs));
    }

    /** @param array<string,mixed> $manifest @return array<string,mixed> */
    private function status(array $manifest, int $sequence, string $step, string $result, \DateTimeImmutable $observedAt): array
    {
        return [
            'running' => $result === 'running',
            'suite' => 'pregao-live',
            'test' => $step,
            'result' => $result,
            'video_url' => null,
            'stream_url' => null,
            'run_id' => $manifest['run_id'],
            'sequence' => $sequence,
            'step' => $step,
            'screenshot_url' => null,
            'observed_at' => $observedAt->format(DATE_ATOM),
            'started_at' => $manifest['created_at'],
            'manifest_hash' => $manifest['manifest_hash'],
        ];
    }
}
