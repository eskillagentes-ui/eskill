<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Pregao;

use App\Services\Pregao\PregaoEventExplorerService;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

/** @covers \App\Services\Pregao\PregaoEventExplorerService */
final class PregaoEventExplorerServiceTest extends TestCase
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

    private function insertEvent(
        PDO $db,
        int $accountId,
        string $type,
        string $ts,
        array $payload,
        string $source = 'live'
    ): void {
        $stmt = $db->prepare(
            'INSERT INTO pregao_events (account_id, type, ts, payload, source) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$accountId, $type, $ts, json_encode($payload, JSON_THROW_ON_ERROR), $source]);
    }

    private function makeService(PDO $db, bool $seedEnabled = false): PregaoEventExplorerService
    {
        return new PregaoEventExplorerService($db, ['seed_enabled' => $seedEnabled]);
    }

    public function testIsolaContaOrdenaDescEExpoeEnvelopeReadOnly(): void
    {
        $db = $this->makeDb();
        $this->insertEvent($db, 1335, 'op', '2026-08-04 10:00:00', ['robot' => 'A', 'msg' => 'primeiro']);
        $this->insertEvent($db, 1335, 'op', '2026-08-04 11:00:00', ['robot' => 'B', 'msg' => 'segundo']);
        $this->insertEvent($db, 9999, 'op', '2026-08-04 12:00:00', ['robot' => 'X', 'msg' => 'outra conta']);

        $result = $this->makeService($db)->list(1335);

        self::assertTrue($result['read_only']);
        self::assertCount(2, $result['events']);
        self::assertSame('segundo', $result['events'][0]['payload']['msg']);
        self::assertSame('primeiro', $result['events'][1]['payload']['msg']);
        self::assertSame(2, $result['pagination']['total']);
        $json = json_encode($result, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('outra conta', $json);
    }

    public function testPerPageNuncaUltrapassaCem(): void
    {
        $db = $this->makeDb();
        $this->insertEvent($db, 1335, 'op', '2026-08-04 10:00:00', ['msg' => 'x']);

        $result = $this->makeService($db)->list(1335, ['per_page' => 500]);

        self::assertSame(100, $result['pagination']['per_page']);
    }

    /**
     * @dataProvider invalidPaginationProvider
     * @param array<string, mixed> $filters
     */
    public function testPaginacaoInvalidaFalhaFechado(array $filters): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->makeService($this->makeDb())->list(1335, $filters);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidPaginationProvider(): array
    {
        return [
            'page texto' => [['page' => 'abc']],
            'page array' => [['page' => ['2']]],
            'per_page texto' => [['per_page' => 'abc']],
            'per_page array' => [['per_page' => ['25']]],
        ];
    }

    public function testPaginacaoServerSideComMetadados(): void
    {
        $db = $this->makeDb();
        foreach ([1, 2, 3] as $i) {
            $this->insertEvent($db, 1335, 'op', "2026-08-04 10:00:0{$i}", ['msg' => "evento {$i}"]);
        }

        $result = $this->makeService($db)->list(1335, ['page' => 2, 'per_page' => 2]);

        self::assertCount(1, $result['events']);
        self::assertSame('evento 1', $result['events'][0]['payload']['msg']);
        self::assertSame(2, $result['pagination']['page']);
        self::assertSame(3, $result['pagination']['total']);
        self::assertSame(2, $result['pagination']['pages']);
        self::assertTrue($result['pagination']['has_prev']);
        self::assertFalse($result['pagination']['has_next']);
    }

    public function testFiltraPorTipoFonteEIntervaloDeDatas(): void
    {
        $db = $this->makeDb();
        $this->insertEvent($db, 1335, 'op', '2026-08-01 10:00:00', ['msg' => 'antigo']);
        $this->insertEvent($db, 1335, 'op', '2026-08-03 10:00:00', ['msg' => 'no intervalo']);
        $this->insertEvent($db, 1335, 'sale', '2026-08-03 11:00:00', ['order_id' => 'X1']);
        $this->insertEvent($db, 1335, 'op', '2026-08-04 10:00:00', ['msg' => 'depois']);

        $result = $this->makeService($db)->list(1335, [
            'type' => 'op',
            'source' => 'live',
            'from' => '2026-08-02',
            'to' => '2026-08-03',
        ]);

        self::assertCount(1, $result['events']);
        self::assertSame('no intervalo', $result['events'][0]['payload']['msg']);
    }

    public function testSeedFicaOcultoQuandoSeedDesabilitado(): void
    {
        $db = $this->makeDb();
        $this->insertEvent($db, 1335, 'op', '2026-08-04 10:00:00', ['msg' => 'real'], 'live');
        $this->insertEvent($db, 1335, 'op', '2026-08-04 11:00:00', ['msg' => 'sintético'], 'seed');

        $all = $this->makeService($db)->list(1335);
        self::assertCount(1, $all['events']);
        self::assertSame('real', $all['events'][0]['payload']['msg']);

        $seedOnly = $this->makeService($db)->list(1335, ['source' => 'seed']);
        self::assertSame([], $seedOnly['events']);
        self::assertSame(0, $seedOnly['pagination']['total']);
    }

    public function testPayloadSanitizadoPorAllowlistPorTipo(): void
    {
        $db = $this->makeDb();
        $this->insertEvent($db, 1335, 'op', '2026-08-04 10:00:00', [
            'robot' => 'SENTINELA',
            'level' => 'info',
            'icon' => '🛡️',
            'msg' => 'ciclo ok',
            'sku' => 'MLB123',
            'internal_token' => 'segredo interno',
            'meta' => ['question_id' => '42'],
        ]);
        $this->insertEvent($db, 1335, 'qa.status', '2026-08-04 10:01:00', [
            'suite' => 'smoke',
            'test' => 'login',
            'result' => 'passed',
            'running' => false,
            'stream_url' => 'https://interno/stream?token=abc',
            'video_url' => 'https://interno/video?token=abc',
        ]);
        $this->insertEvent($db, 1335, 'metric.update', '2026-08-04 10:02:00', [
            'key' => 'perguntas_7d',
            'value' => ['abertas' => [['text_preview' => 'pergunta privada']]],
            'flash' => 'green',
        ]);

        $result = $this->makeService($db)->list(1335);
        $byType = array_column($result['events'], null, 'type');

        self::assertSame(
            ['icon' => '🛡️', 'level' => 'info', 'msg' => 'ciclo ok', 'robot' => 'SENTINELA', 'sku' => 'MLB123'],
            $byType['op']['payload']
        );
        self::assertSame(
            ['result' => 'passed', 'running' => false, 'suite' => 'smoke', 'test' => 'login'],
            $byType['qa.status']['payload']
        );
        self::assertSame(
            ['flash' => 'green', 'key' => 'perguntas_7d'],
            $byType['metric.update']['payload']
        );
        $json = json_encode($result, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('segredo interno', $json);
        self::assertStringNotContainsString('stream_url', $json);
        self::assertStringNotContainsString('token', $json);
        self::assertStringNotContainsString('pergunta privada', $json);
    }

    public function testPayloadRedigeCredenciaisELimitaCamposLivres(): void
    {
        $db = $this->makeDb();
        $this->insertEvent($db, 1335, 'op', '2026-08-04 10:00:00', [
            'msg' => 'Authorization: Basic dXNlcjpwYXNz Bearer "TOP_SECRET_MARKER" '
                . 'token="QA SECRET MARKER" ' . str_repeat('x', 700),
        ]);

        $result = $this->makeService($db)->list(1335);
        $message = $result['events'][0]['payload']['msg'];

        self::assertStringNotContainsString('TOP_SECRET_MARKER', $message);
        self::assertStringNotContainsString('dXNlcjpwYXNz', $message);
        self::assertStringNotContainsString('QA SECRET MARKER', $message);
        self::assertLessThanOrEqual(500, strlen($message));
        self::assertStringContainsString('[REDACTED]', $message);
    }

    public function testAgentStatusReutilizaValidacaoExistente(): void
    {
        $db = $this->makeDb();
        $valid = [
            'agent' => 'sentinela',
            'status' => 'success',
            'reason' => 'legacy_read_complete',
            'correlation_id' => 'agent24x7-20260804T120000Z-0123abcd:1335',
            'attempts' => 1,
            'state_changed' => false,
            'ml_write_automation' => false,
        ];
        $this->insertEvent($db, 1335, 'agent.status', '2026-08-04 10:00:00', $valid);
        $this->insertEvent($db, 1335, 'agent.status', '2026-08-04 11:00:00', $valid + ['extra' => 'não permitido']);

        $result = $this->makeService($db)->list(1335, ['type' => 'agent.status']);

        self::assertCount(2, $result['events']);
        self::assertNull($result['events'][0]['payload'], 'payload fora do contrato deve falhar fechado');
        self::assertSame('sentinela', $result['events'][1]['payload']['agent']);
        self::assertStringNotContainsString(
            'não permitido',
            json_encode($result, JSON_THROW_ON_ERROR)
        );
    }

    public function testTimestampComMilissegundosViraIsoSaoPaulo(): void
    {
        $db = $this->makeDb();
        $this->insertEvent($db, 1335, 'op', '2026-08-04 12:00:00.123', ['msg' => 'ms']);

        $result = $this->makeService($db)->list(1335);

        self::assertSame('2026-08-04T12:00:00-03:00', $result['events'][0]['ts']);
    }

    public function testTipoInvalidoFalhaFechado(): void
    {
        $db = $this->makeDb();
        $this->expectException(InvalidArgumentException::class);
        $this->makeService($db)->list(1335, ['type' => 'internal.debug']);
    }

    public function testFonteInvalidaFalhaFechado(): void
    {
        $db = $this->makeDb();
        $this->expectException(InvalidArgumentException::class);
        $this->makeService($db)->list(1335, ['source' => 'backdoor']);
    }

    public function testDataInvalidaFalhaFechado(): void
    {
        $db = $this->makeDb();
        $this->expectException(InvalidArgumentException::class);
        $this->makeService($db)->list(1335, ['from' => '2026-13-40']);
    }

    public function testContaInvalidaFalhaFechado(): void
    {
        $db = $this->makeDb();
        $this->expectException(InvalidArgumentException::class);
        $this->makeService($db)->list(0);
    }
}
