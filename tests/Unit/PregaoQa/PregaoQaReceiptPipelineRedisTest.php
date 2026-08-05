<?php

declare(strict_types=1);

namespace Tests\Unit\PregaoQa;

use App\Services\Pregao\PregaoEmitService;
use App\Services\Pregao\PregaoQaProof;
use App\Services\Pregao\PregaoQaRunService;
use App\Services\Pregao\PregaoQaStatusProducer;
use App\Services\Pregao\PregaoQaWorkerProtocol;
use PDO;
use PHPUnit\Framework\TestCase;
use Redis;

final class PregaoQaReceiptPipelineRedisTest extends TestCase
{
    private Redis $redis;
    /** @var resource|null */
    private $redisProcess = null;
    private string $redisDirectory = '';

    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists(Redis::class)) {
            self::markTestSkipped('ext-redis indisponível');
        }
        $binary = trim((string) shell_exec('command -v redis-server 2>/dev/null'));
        if ($binary === '' || !is_executable($binary)) {
            self::markTestSkipped('redis-server indisponível');
        }
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if (!is_resource($socket)) {
            self::fail('porta Redis descartável indisponível: ' . $error);
        }
        $address = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr(strrchr($address, ':'), 1);
        $this->redisDirectory = sys_get_temp_dir() . '/pregao-qa-redis-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->redisDirectory, 0700));
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $this->redisDirectory . '/stdout.log', 'a'],
            2 => ['file', $this->redisDirectory . '/stderr.log', 'a'],
        ];
        $pipes = [];
        $this->redisProcess = proc_open([
            $binary,
            '--bind', '127.0.0.1',
            '--protected-mode', 'yes',
            '--port', (string) $port,
            '--save', '',
            '--appendonly', 'no',
            '--dir', $this->redisDirectory,
        ], $descriptors, $pipes, $this->redisDirectory);
        self::assertIsResource($this->redisProcess);
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }
        $this->redis = new Redis();
        $connected = false;
        for ($attempt = 0; $attempt < 100; $attempt++) {
            try {
                $connected = $this->redis->connect('127.0.0.1', $port, 0.05);
            } catch (\Throwable) {
                $connected = false;
            }
            if ($connected) {
                break;
            }
            usleep(20_000);
        }
        self::assertTrue($connected, 'redis-server descartável não iniciou');
        self::assertTrue($this->redis->flushDB());
    }

    protected function tearDown(): void
    {
        if (isset($this->redis)) {
            try {
                $this->redis->rawCommand('SHUTDOWN', 'NOSAVE');
            } catch (\Throwable) {
            }
        }
        if (is_resource($this->redisProcess)) {
            $status = proc_get_status($this->redisProcess);
            if (($status['running'] ?? false) === true) {
                proc_terminate($this->redisProcess);
            }
            proc_close($this->redisProcess);
        }
        if ($this->redisDirectory !== '' && is_dir($this->redisDirectory)) {
            foreach (scandir($this->redisDirectory) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    @unlink($this->redisDirectory . '/' . $entry);
                }
            }
            @rmdir($this->redisDirectory);
        }
        parent::tearDown();
    }

    public function testRealRedisAdvancesImmutableReceiptsAndLatestWithoutOldReplayProjection(): void
    {
        [$proof, $manifest] = $this->seedRun('123e4567-e89b-42d3-a456-426614174000');
        $db = $this->sqliteEvents();
        $runs = new PregaoQaRunService($this->redis, $proof);
        $producer = new PregaoQaStatusProducer(new PregaoEmitService($db, $this->redis), $proof, $runs);
        $protocols = [];
        $events = [];

        foreach (PregaoQaWorkerProtocol::STEPS as $index => $step) {
            $sequence = $index + 1;
            $protocol = [
                'run_id' => $manifest['run_id'],
                'sequence' => $sequence,
                'step' => $step,
                'result' => $sequence === 5 ? 'passed' : 'running',
                'screenshot' => 'latest.png',
                'cursor' => ['x' => 10 + $sequence, 'y' => 20 + $sequence],
                'observed_at' => (new \DateTimeImmutable('-' . (6 - $sequence) . ' seconds', new \DateTimeZone('UTC')))->format(DATE_ATOM),
            ];
            $runs->updateState($manifest, $protocol);
            $events[] = $producer->emit($manifest, $protocol);
            $protocols[] = $protocol;
        }

        self::assertSame([1, 2, 3, 4, 5], array_column(array_column($events, 'payload'), 'sequence'));
        self::assertSame(5, (int) $db->query("SELECT COUNT(*) FROM pregao_events WHERE type = 'qa.status'")->fetchColumn());
        for ($sequence = 1; $sequence <= 5; $sequence++) {
            $receipt = json_decode((string) $this->redis->get(
                PregaoQaRunService::receiptKey($manifest['run_id'], $sequence)
            ), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame($sequence, $receipt['sequence']);
        }
        $receiptKeys = $this->redis->keys('pregao:qa:receipt:' . $manifest['run_id'] . ':[1-5]');
        sort($receiptKeys, SORT_STRING);
        self::assertCount(5, $receiptKeys, 'deve existir um receipt imutável por sequência');
        $latest = json_decode((string) $this->redis->get(
            PregaoQaRunService::latestReceiptKey(1335)
        ), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(5, $latest['sequence']);
        self::assertSame($events[4]['payload']['signature'], $latest['status_signature']);
        self::assertTrue($runs->isStatusAuthoritative($events[4]['payload'], 1335));
        self::assertFalse($runs->isStatusAuthoritative($events[0]['payload'], 1335));
        $fanout = array_map(
            static fn (string $json): array => json_decode($json, true, 512, JSON_THROW_ON_ERROR),
            $this->redis->lRange('pregao:fanout', 0, -1)
        );
        self::assertSame([5, 4, 3, 2, 1], array_column(array_column($fanout, 'payload'), 'sequence'));

        $rowsBeforeReplay = (int) $db->query("SELECT COUNT(*) FROM pregao_events WHERE type = 'qa.status'")->fetchColumn();
        $fanoutBeforeReplay = $this->redis->lLen('pregao:fanout');
        $oldReplay = $producer->emit($manifest, $protocols[0]);
        $terminalReplay = $producer->emit($manifest, $protocols[4]);
        self::assertSame($events[0], $oldReplay);
        self::assertSame($events[4], $terminalReplay);
        self::assertSame($rowsBeforeReplay, (int) $db->query("SELECT COUNT(*) FROM pregao_events WHERE type = 'qa.status'")->fetchColumn());
        self::assertSame($fanoutBeforeReplay, $this->redis->lLen('pregao:fanout'));
        self::assertSame(5, json_decode((string) $this->redis->get(
            PregaoQaRunService::latestReceiptKey(1335)
        ), true, 512, JSON_THROW_ON_ERROR)['sequence']);
        self::assertGreaterThan(PregaoQaRunService::RUN_TTL_SECONDS, $this->redis->ttl(
            PregaoQaRunService::receiptKey($manifest['run_id'], 5)
        ));
        self::assertGreaterThan(PregaoQaRunService::RUN_TTL_SECONDS, $this->redis->ttl(
            PregaoQaRunService::stateKey($manifest['run_id'])
        ));
        self::assertGreaterThan(0, $this->redis->ttl(PregaoQaRunService::cooldownAccountKey(1335)));
    }

    public function testCursorComparisonUsesCanonicalObjectSemanticsAfterRedisCjsonReordersKeys(): void
    {
        [$proof, $manifest] = $this->seedRun('223e4567-e89b-42d3-a456-426614174000');
        $protocol = [
            'run_id' => $manifest['run_id'],
            'sequence' => 1,
            'step' => 'dashboard',
            'result' => 'running',
            'screenshot' => 'latest.png',
            'cursor' => ['x' => 10, 'y' => 20],
            'observed_at' => (new \DateTimeImmutable('-1 second', new \DateTimeZone('UTC')))->format(DATE_ATOM),
        ];
        $state = [
            'run_id' => $manifest['run_id'],
            'account_id' => 1335,
            'manifest_hash' => $manifest['manifest_hash'],
            'status' => 'running',
            'sequence' => 1,
            'step' => 'dashboard',
            'screenshot_url' => '/qa/frame/' . $manifest['run_id'],
            'cursor' => ['y' => 20, 'x' => 10],
            'observed_at' => $protocol['observed_at'],
        ];
        $this->redis->set(PregaoQaRunService::stateKey($manifest['run_id']), json_encode($state, JSON_THROW_ON_ERROR));

        self::assertTrue((new PregaoQaRunService($this->redis, $proof))->protocolMatchesPersistedState($manifest, $protocol));
    }

    public function testRecoveryRepairsCrashBetweenPersistAndConfirmWithoutDuplicatingDbOrFanout(): void
    {
        [$proof, $manifest] = $this->seedRun('323e4567-e89b-42d3-a456-426614174000');
        $db = $this->sqliteEvents();
        $runs = new PregaoQaRunService($this->redis, $proof);
        $emitter = new PregaoEmitService($db, $this->redis);
        $producer = new PregaoQaStatusProducer($emitter, $proof, $runs);
        $protocol = $this->runningProtocol($manifest, 1);
        $runs->updateState($manifest, $protocol);
        $status = $this->signedStatus($proof, $manifest, $protocol);
        $persisted = $emitter->persistTrustedQaStatus($status, 1335, $proof);
        $rawJob = json_encode(['run_id' => $manifest['run_id']], JSON_THROW_ON_ERROR);
        $this->redis->lPush(PregaoQaRunService::PENDING_KEY, $rawJob);

        self::assertSame(0, $this->redis->lLen('pregao:fanout'));
        self::assertFalse($this->redis->get(PregaoQaRunService::receiptKey($manifest['run_id'], 1)));
        self::assertSame(1, $runs->recoverPending(
            static fn (array $recoveryManifest, array $state): bool => $producer->repairEvidence($recoveryManifest, $state)
        ));

        $receipt = json_decode((string) $this->redis->get(
            PregaoQaRunService::receiptKey($manifest['run_id'], 1)
        ), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($persisted['event_id'], $receipt['event_id']);
        self::assertSame(1, (int) $db->query("SELECT COUNT(*) FROM pregao_events WHERE type = 'qa.status'")->fetchColumn());
        self::assertSame(1, $this->redis->lLen('pregao:fanout'));
        self::assertTrue($producer->repairEvidence($manifest, json_decode(
            (string) $this->redis->get(PregaoQaRunService::stateKey($manifest['run_id'])),
            true,
            512,
            JSON_THROW_ON_ERROR
        )));
        self::assertSame(1, (int) $db->query("SELECT COUNT(*) FROM pregao_events WHERE type = 'qa.status'")->fetchColumn());
        self::assertSame(1, $this->redis->lLen('pregao:fanout'));
    }

    public function testRecoveryRepairsCrashBetweenConfirmAndPublishExactlyOnce(): void
    {
        [$proof, $manifest] = $this->seedRun('423e4567-e89b-42d3-a456-426614174000');
        $db = $this->sqliteEvents();
        $runs = new PregaoQaRunService($this->redis, $proof);
        $emitter = new PregaoEmitService($db, $this->redis);
        $producer = new PregaoQaStatusProducer($emitter, $proof, $runs);
        $protocol = $this->runningProtocol($manifest, 1);
        $runs->updateState($manifest, $protocol);
        $status = $this->signedStatus($proof, $manifest, $protocol);
        $persisted = $emitter->persistTrustedQaStatus($status, 1335, $proof);
        self::assertTrue($runs->confirmEvidence(
            $manifest,
            $status,
            $persisted['event_id'],
            $persisted['event']['ts']
        ));
        $rawJob = json_encode(['run_id' => $manifest['run_id']], JSON_THROW_ON_ERROR);
        $this->redis->lPush(PregaoQaRunService::PENDING_KEY, $rawJob);

        self::assertSame(0, $this->redis->lLen('pregao:fanout'));
        self::assertSame(1, $runs->recoverPending(
            static fn (array $recoveryManifest, array $state): bool => $producer->repairEvidence($recoveryManifest, $state)
        ));
        self::assertSame(1, $this->redis->lLen('pregao:fanout'));
        self::assertSame(1, (int) $db->query("SELECT COUNT(*) FROM pregao_events WHERE type = 'qa.status'")->fetchColumn());
        self::assertTrue($producer->repairEvidence($manifest, json_decode(
            (string) $this->redis->get(PregaoQaRunService::stateKey($manifest['run_id'])),
            true,
            512,
            JSON_THROW_ON_ERROR
        )));
        self::assertSame(1, $this->redis->lLen('pregao:fanout'));
        self::assertSame(1, (int) $db->query("SELECT COUNT(*) FROM pregao_events WHERE type = 'qa.status'")->fetchColumn());
    }

    /** @return array{0:PregaoQaProof,1:array<string,mixed>} */
    private function seedRun(string $runId): array
    {
        $proof = new PregaoQaProof(str_repeat('r', 32));
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $manifest = $proof->signManifest([
            'run_id' => $runId,
            'account_id' => 1335,
            'user_id' => 77,
            'created_at' => $now->modify('-1 minute')->format(DATE_ATOM),
            'expires_at' => $now->modify('+14 minutes')->format(DATE_ATOM),
        ]);
        $state = [
            'run_id' => $runId,
            'account_id' => 1335,
            'manifest_hash' => $manifest['manifest_hash'],
            'status' => 'queued',
            'sequence' => 0,
            'updated_at' => $now->format(DATE_ATOM),
        ];
        $this->redis->setex(PregaoQaRunService::manifestKey($runId), PregaoQaRunService::RUN_TTL_SECONDS, json_encode($manifest, JSON_THROW_ON_ERROR));
        $this->redis->setex(PregaoQaRunService::stateKey($runId), PregaoQaRunService::RUN_TTL_SECONDS, json_encode($state, JSON_THROW_ON_ERROR));
        return [$proof, $manifest];
    }

    private function sqliteEvents(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE pregao_events (id INTEGER PRIMARY KEY AUTOINCREMENT, account_id INTEGER, type TEXT, ts TEXT, payload TEXT, source TEXT)');
        return $db;
    }

    /** @param array<string,mixed> $manifest @return array<string,mixed> */
    private function runningProtocol(array $manifest, int $sequence): array
    {
        return [
            'run_id' => $manifest['run_id'],
            'sequence' => $sequence,
            'step' => PregaoQaWorkerProtocol::STEPS[$sequence - 1],
            'result' => 'running',
            'screenshot' => 'latest.png',
            'cursor' => ['x' => 10, 'y' => 20],
            'observed_at' => (new \DateTimeImmutable('-1 second', new \DateTimeZone('UTC')))->format(DATE_ATOM),
        ];
    }

    /**
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $protocol
     * @return array<string,mixed>
     */
    private function signedStatus(PregaoQaProof $proof, array $manifest, array $protocol): array
    {
        $frameUrl = $protocol['screenshot'] === 'latest.png' ? '/qa/frame/' . $manifest['run_id'] : null;
        return $proof->signStatus([
            'running' => $protocol['result'] === 'running',
            'suite' => 'pregao-live',
            'test' => $protocol['step'],
            'result' => $protocol['result'],
            'video_url' => null,
            'stream_url' => $frameUrl === null ? null : '/qa/live/' . $manifest['run_id'],
            'run_id' => $manifest['run_id'],
            'sequence' => $protocol['sequence'],
            'step' => $protocol['step'],
            'screenshot_url' => $frameUrl,
            'observed_at' => $protocol['observed_at'],
            'started_at' => $manifest['created_at'],
            'manifest_hash' => $manifest['manifest_hash'],
        ], (int) $manifest['account_id']);
    }
}
