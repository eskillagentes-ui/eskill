<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Pregao;

use App\Services\Pregao\PregaoSnapshotService;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @covers \App\Services\Pregao\PregaoSnapshotService
 */
final class PregaoSnapshotQaTest extends TestCase
{
    private function makeDb(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec(
            'CREATE TABLE pregao_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                account_id INTEGER,
                type TEXT,
                ts TEXT,
                payload TEXT,
                source TEXT
            )'
        );
        return $db;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadLatestQa(PDO $db, int $accountId): array
    {
        $service = new PregaoSnapshotService($db, null, ['seed_enabled' => false]);
        $method = new ReflectionMethod(PregaoSnapshotService::class, 'loadLatestQa');
        $method->setAccessible(true);
        /** @var array<string, mixed> $qa */
        $qa = $method->invoke($service, $accountId);
        return $qa;
    }

    public function testSemEventoQaStatusRealMarcaExecutedFalseSemInventarNada(): void
    {
        $qa = $this->loadLatestQa($this->makeDb(), 1335);

        self::assertFalse($qa['executed'], 'sem qa.status real, deve declarar não executado');
        self::assertFalse($qa['running']);
        self::assertNull($qa['result']);
        self::assertNull($qa['video_url']);
        self::assertNull($qa['stream_url']);
        self::assertSame([], $qa['log'], 'nenhum log artificial deve ser criado');
    }

    public function testComEventoQaStatusRealMarcaExecutedTrue(): void
    {
        $db = $this->makeDb();
        $payload = [
            'running' => false,
            'suite' => 'smoke',
            'test' => 'login',
            'result' => 'passed',
            // Payload nunca decide o flag de execução — o backend decide.
            'executed' => false,
        ];
        $db->prepare(
            'INSERT INTO pregao_events (account_id, type, ts, payload, source) VALUES (?, ?, ?, ?, ?)'
        )->execute([1335, 'qa.status', '2026-08-04 12:00:00', json_encode($payload, JSON_THROW_ON_ERROR), 'live']);

        $qa = $this->loadLatestQa($db, 1335);

        self::assertTrue($qa['executed'], 'evento real registrado deve marcar executed=true');
        self::assertSame('passed', $qa['result']);
        self::assertSame('smoke', $qa['suite']);
        self::assertCount(1, $qa['log']);
    }
}
