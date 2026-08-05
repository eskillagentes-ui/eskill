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

    /** @param array<string, mixed>|string $payload */
    private function insertQa(PDO $db, int $accountId, array|string $payload): void
    {
        $encoded = is_array($payload) ? json_encode($payload, JSON_THROW_ON_ERROR) : $payload;
        $db->prepare(
            'INSERT INTO pregao_events (account_id, type, ts, payload, source) VALUES (?, ?, ?, ?, ?)'
        )->execute([$accountId, 'qa.status', '2026-08-04 12:00:00', $encoded, 'live']);
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

    public function testLinhaQaBemFormadaNaoProvaExecucaoSemProdutorConfiavel(): void
    {
        $db = $this->makeDb();
        $payload = [
            'running' => false,
            'suite' => 'smoke',
            'test' => 'login',
            'result' => 'passed',
            'stream_url' => '/qa/stream/session-1',
        ];
        $this->insertQa($db, 1335, $payload);

        $qa = $this->loadLatestQa($db, 1335);

        self::assertFalse($qa['executed'], 'linha persistida isolada não deve provar execução');
        self::assertNull($qa['result']);
        self::assertNull($qa['suite']);
        self::assertNull($qa['stream_url']);
        self::assertSame([], $qa['log']);
    }

    public function testPayloadQaVazioMalformadoOuComCampoExtraFalhaFechado(): void
    {
        $db = $this->makeDb();
        $this->insertQa($db, 1335, []);
        $this->insertQa($db, 1336, '{not-json');
        $this->insertQa($db, 1337, [
            'running' => false,
            'suite' => 'smoke',
            'test' => 'login',
            'result' => 'passed',
            'internal_secret' => 'não pode vazar',
        ]);

        foreach ([1335, 1336, 1337] as $accountId) {
            $qa = $this->loadLatestQa($db, $accountId);
            self::assertFalse($qa['executed']);
            self::assertNull($qa['result']);
            self::assertSame([], $qa['log']);
            self::assertStringNotContainsString(
                'não pode vazar',
                json_encode($qa, JSON_THROW_ON_ERROR)
            );
        }
    }

    public function testUrlQaExternaOuJavascriptFalhaFechado(): void
    {
        $db = $this->makeDb();
        foreach ([
            1335 => 'https://internal.example/token?secret=x',
            1336 => 'javascript:alert(1)',
            1337 => '/qa/../secrets',
        ] as $accountId => $url) {
            $this->insertQa($db, $accountId, [
                'running' => true,
                'suite' => 'smoke',
                'test' => 'login',
                'result' => 'running',
                'stream_url' => $url,
            ]);
            $qa = $this->loadLatestQa($db, $accountId);
            self::assertFalse($qa['executed']);
            self::assertNull($qa['stream_url']);
        }
    }
}
