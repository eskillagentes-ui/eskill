<?php

declare(strict_types=1);

namespace Tests\Unit\PregaoQa;

use App\Services\Pregao\PregaoEmitService;
use App\Services\Pregao\PregaoQaProof;
use App\Services\Pregao\PregaoQaStatusProducer;
use PDO;
use PDOStatement;
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

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($stmt);
        $published = null;
        $redis = $this->createMock(Redis::class);
        $redis->method('publish')->willReturnCallback(static function (string $channel, string $json) use (&$published): int {
            $published = json_decode($json, true);
            return 1;
        });
        $redis->method('lPush')->willReturn(1);
        $redis->method('lTrim')->willReturn(true);

        $producer = new PregaoQaStatusProducer(new PregaoEmitService($pdo, $redis), $proof);
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
}
