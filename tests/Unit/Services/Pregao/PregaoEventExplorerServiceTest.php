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
    public function testListaEventosPaginadosSemCruzarContaNemExporPayloadBruto(): void
    {
        $db = $this->createDatabase();
        $this->insert($db, 1335, 'op', '2026-08-04 12:00:00.123', [
            'robot' => 'COLETOR',
            'level' => 'info',
            'icon' => '•',
            'msg' => 'Coleta concluída',
            'heartbeat' => true,
            'access_token' => 'nao-pode-vazar',
        ], 'live');
        $this->insert($db, 1335, 'metric.update', '2026-08-04 12:01:00.000', [
            'key' => 'visitas_7d',
            'value' => 120,
            'flash' => 'green',
            'internal_error' => 'nao-pode-vazar',
        ], 'live');
        $this->insert($db, 1335, 'op', '2026-08-04 12:02:00.456', [
            'robot' => 'SENTINELA',
            'level' => 'alert',
            'icon' => '!',
            'msg' => 'Atenção operacional',
        ], 'live');
        $this->insert($db, 1335, 'op', '2026-08-04 12:03:00.000', [
            'robot' => 'SEED',
            'msg' => 'Evento sintético',
        ], 'seed');
        $this->insert($db, 9999, 'op', '2026-08-04 12:04:00.000', [
            'robot' => 'OUTRA_CONTA',
            'msg' => 'Não pode cruzar conta',
        ], 'live');

        $result = (new PregaoEventExplorerService($db, false))->listForAccount(1335, [
            'page' => 1,
            'per_page' => 2,
            'type' => 'op',
            'source' => 'live',
            'from' => '2026-08-04',
            'to' => '2026-08-04',
        ]);

        self::assertTrue($result['read_only']);
        self::assertSame([
            'page' => 1,
            'per_page' => 2,
            'total' => 2,
            'pages' => 1,
            'has_previous' => false,
            'has_next' => false,
        ], $result['pagination']);
        self::assertCount(2, $result['items']);
        self::assertSame('SENTINELA', $result['items'][0]['details']['robot']);
        self::assertSame('2026-08-04T12:02:00-03:00', $result['items'][0]['ts']);
        self::assertSame('COLETOR', $result['items'][1]['details']['robot']);
        self::assertSame(
            ['heartbeat', 'icon', 'level', 'msg', 'robot'],
            array_keys($result['items'][1]['details'])
        );
        self::assertStringNotContainsString('access_token', json_encode($result, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('OUTRA_CONTA', json_encode($result, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('Evento sintético', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testPaginaçãoÉDeterminísticaESeedPermaneceFailClosed(): void
    {
        $db = $this->createDatabase();
        $this->insert($db, 1335, 'op', '2026-08-04 12:00:00', ['robot' => 'A', 'msg' => 'A'], 'live');
        $this->insert($db, 1335, 'metric.update', '2026-08-04 12:01:00', [
            'key' => 'vendas_hoje', 'value' => 1,
        ], 'live');
        $this->insert($db, 1335, 'index.tick', '2026-08-04 12:02:00', [
            'value' => 999.1, 'factors_active' => 5, 'factors_total' => 5,
        ], 'live');
        $this->insert($db, 1335, 'op', '2026-08-04 12:03:00', ['robot' => 'SEED', 'msg' => 'seed'], 'seed');

        $service = new PregaoEventExplorerService($db, false);
        $pageOne = $service->listForAccount(1335, ['page' => 1, 'per_page' => 2]);
        $pageTwo = $service->listForAccount(1335, ['page' => 2, 'per_page' => 2]);

        self::assertSame(3, $pageOne['pagination']['total']);
        self::assertSame(2, $pageOne['pagination']['pages']);
        self::assertSame(['index.tick', 'metric.update'], array_column($pageOne['items'], 'type'));
        self::assertSame(['op'], array_column($pageTwo['items'], 'type'));
        self::assertTrue($pageOne['pagination']['has_next']);
        self::assertTrue($pageTwo['pagination']['has_previous']);
    }

    public function testSeedSóÉConsultávelQuandoConfiguraçãoPermite(): void
    {
        $db = $this->createDatabase();
        $this->insert($db, 1335, 'op', '2026-08-04 12:00:00', ['robot' => 'LIVE', 'msg' => 'live'], 'live');
        $this->insert($db, 1335, 'op', '2026-08-04 12:01:00', ['robot' => 'SEED', 'msg' => 'seed'], 'seed');

        $result = (new PregaoEventExplorerService($db, true))->listForAccount(1335, [
            'source' => 'seed',
        ]);

        self::assertSame(1, $result['pagination']['total']);
        self::assertSame('seed', $result['items'][0]['source']);
        self::assertSame('SEED', $result['items'][0]['details']['robot']);
    }

    public function testSanitizaStatusDosAgentesETruncaStringsLongas(): void
    {
        $db = $this->createDatabase();
        $this->insert($db, 1335, 'agent.status', '2026-08-04 12:00:00.000', [
            'agent' => 'collector',
            'status' => 'success',
            'reason' => 'legacy_read_complete',
            'correlation_id' => 'agent24x7-20260804T120000Z-0123abcd:1335',
            'attempts' => 1,
            'state_changed' => false,
            'ml_write_automation' => false,
            'debug' => str_repeat('x', 500),
        ], 'live');
        $this->insert($db, 1335, 'op', '2026-08-04 12:01:00.000', [
            'robot' => 'SISTEMA',
            'msg' => str_repeat('m', 500),
        ], 'live');

        $result = (new PregaoEventExplorerService($db, false))->listForAccount(1335, ['per_page' => 10]);
        $byType = array_column($result['items'], null, 'type');

        self::assertSame(300, mb_strlen($byType['op']['details']['msg']));
        self::assertSame([
            'agent',
            'attempts',
            'correlation_id',
            'ml_write_automation',
            'reason',
            'state_changed',
            'status',
        ], array_keys($byType['agent.status']['details']));
        self::assertStringNotContainsString('debug', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testRejeitaParâmetrosInválidosAntesDeConsultar(): void
    {
        $service = new PregaoEventExplorerService($this->createDatabase(), false);
        $invalidFilters = [
            ['page' => 0],
            ['per_page' => 101],
            ['type' => 'unknown.event'],
            ['source' => 'other'],
            ['source' => 'seed'],
            ['from' => '04/08/2026'],
            ['from' => '2026-08-05', 'to' => '2026-08-04'],
        ];

        foreach ($invalidFilters as $filters) {
            try {
                $service->listForAccount(1335, $filters);
                self::fail('Filtro inválido deveria ser rejeitado');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }

        $this->expectException(InvalidArgumentException::class);
        $service->listForAccount(0);
    }

    private function createDatabase(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec(
            'CREATE TABLE pregao_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                account_id INTEGER NOT NULL,
                type TEXT NOT NULL,
                ts TEXT NOT NULL,
                payload TEXT NOT NULL,
                source TEXT NOT NULL
            )'
        );
        return $db;
    }

    /** @param array<string, mixed> $payload */
    private function insert(
        PDO $db,
        int $accountId,
        string $type,
        string $timestamp,
        array $payload,
        string $source
    ): void {
        $stmt = $db->prepare(
            'INSERT INTO pregao_events (account_id, type, ts, payload, source) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $accountId,
            $type,
            $timestamp,
            json_encode($payload, JSON_THROW_ON_ERROR),
            $source,
        ]);
    }
}
