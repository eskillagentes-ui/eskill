<?php

declare(strict_types=1);

namespace Tests\Unit\PregaoQa;

use App\Services\Pregao\PregaoQaProof;
use App\Services\Pregao\PregaoSnapshotService;
use App\Services\Pregao\PregaoStreamService;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class PregaoQaSnapshotStreamTest extends TestCase
{
    public function testSnapshotLoadsOnlyLatestQaWithValidProof(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE pregao_events (id INTEGER PRIMARY KEY AUTOINCREMENT, account_id INTEGER, type TEXT, ts TEXT, payload TEXT, source TEXT)');
        $proof = new PregaoQaProof(str_repeat('s', 32));
        $manifest = $proof->signManifest([
            'run_id' => '123e4567-e89b-42d3-a456-426614174000',
            'account_id' => 1335,
            'user_id' => 77,
            'created_at' => '2026-08-05T12:00:00+00:00',
            'expires_at' => '2099-08-05T12:15:00+00:00',
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
            'observed_at' => '2026-08-05T12:01:00+00:00',
            'started_at' => $manifest['created_at'],
            'manifest_hash' => $manifest['manifest_hash'],
        ], 1335);
        $db->prepare('INSERT INTO pregao_events (account_id,type,ts,payload,source) VALUES (?,?,?,?,?)')
            ->execute([1335, 'qa.status', '2026-08-05 12:01:00', json_encode($payload), 'live']);

        $service = new PregaoSnapshotService($db, null, ['seed_enabled' => false], null, null, $proof);
        $method = new ReflectionMethod($service, 'loadLatestQa');
        $method->setAccessible(true);
        $qa = $method->invoke($service, 1335);
        self::assertTrue($qa['trusted']);
        self::assertSame('passed', $qa['status']);
        self::assertSame('passed', $qa['result']);
        self::assertSame($manifest['run_id'], $qa['run_id']);
        self::assertSame(60000, $qa['elapsed_ms']);

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
        self::assertTrue(PregaoStreamService::isEventAllowedForAccount($event, 1335, $proof));
        $event['payload']['sequence'] = 2;
        self::assertFalse(PregaoStreamService::isEventAllowedForAccount($event, 1335, $proof));
        self::assertFalse(PregaoStreamService::isEventAllowedForAccount($event, 9999, $proof));
    }
}
