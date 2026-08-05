<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Pregao;

use App\Services\Pregao\PregaoAgentStatusService;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;

/** @covers \App\Services\Pregao\PregaoAgentStatusService */
final class PregaoAgentStatusServiceTest extends TestCase
{
    public function testValidadorAceitaReasonsHttpNao2xxDoProdutor(): void
    {
        foreach (['legacy_http_101', 'legacy_http_302'] as $reason) {
            $payload = [
                'agent' => 'collector',
                'status' => 'failed',
                'reason' => $reason,
                'correlation_id' => 'agent24x7-20260804T120000Z-0123abcd:1335',
                'attempts' => 1,
                'state_changed' => false,
                'ml_write_automation' => false,
            ];
            self::assertNotNull(PregaoAgentStatusService::validatePayload($payload, 1335));
        }
    }

    public function testRosterParcialFicaEmAtencao(): void
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
        $payload = [
            'agent' => 'sentinela',
            'status' => 'success',
            'reason' => 'legacy_read_complete',
            'correlation_id' => 'agent24x7-20260804T120000Z-0123abcd:1335',
            'attempts' => 1,
            'state_changed' => false,
            'ml_write_automation' => false,
        ];
        $stmt = $db->prepare(
            'INSERT INTO pregao_events (account_id, type, ts, payload, source) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([1335, 'agent.status', '2026-08-04 12:00:00', json_encode($payload, JSON_THROW_ON_ERROR), 'live']);
        $invalidDatePayload = $payload;
        $invalidDatePayload['agent'] = 'collector';
        $stmt->execute([
            1335,
            'agent.status',
            '2026-02-30 12:00:00',
            json_encode($invalidDatePayload, JSON_THROW_ON_ERROR),
            'live',
        ]);

        $now = new DateTimeImmutable('2026-08-04 12:05:00', new DateTimeZone('America/Sao_Paulo'));
        $summary = (new PregaoAgentStatusService($db))->latestForAccount(1335, false, $now)['summary'];

        self::assertSame(1, $summary['reporting']);
        self::assertSame(4, $summary['attention']);
        self::assertSame('attention', $summary['overall']);
    }

    public function testCorrelacoesMistasNuncaFicamSaudaveis(): void
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
        $stmt = $db->prepare(
            'INSERT INTO pregao_events (account_id, type, ts, payload, source) VALUES (?, ?, ?, ?, ?)'
        );
        foreach (['sentinela', 'collector', 'financeiro', 'otimizador', 'orquestrador'] as $index => $agent) {
            $payload = [
                'agent' => $agent,
                'status' => 'success',
                'reason' => $agent === 'orquestrador' ? 'aggregated' : 'legacy_read_complete',
                'correlation_id' => 'agent24x7-20260804T120000Z-'
                    . ($index === 0 ? 'aaaaaaaa' : 'bbbbbbbb') . ':1335',
                'attempts' => 1,
                'state_changed' => false,
                'ml_write_automation' => false,
            ];
            $stmt->execute([
                1335,
                'agent.status',
                '2026-08-04 12:00:0' . $index,
                json_encode($payload, JSON_THROW_ON_ERROR),
                'live',
            ]);
        }

        $now = new DateTimeImmutable('2026-08-04 12:05:00', new DateTimeZone('America/Sao_Paulo'));
        $summary = (new PregaoAgentStatusService($db))->latestForAccount(1335, false, $now)['summary'];

        self::assertSame(5, $summary['reporting']);
        self::assertGreaterThanOrEqual(1, $summary['attention']);
        self::assertSame('attention', $summary['overall']);
    }

    public function testRetornaUltimoEstadoPorAgenteSemCruzarContaEMarcaStale(): void
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
        $insert = $db->prepare(
            'INSERT INTO pregao_events (account_id, type, ts, payload, source) VALUES (?, ?, ?, ?, ?)'
        );
        $insert->execute([1335, 'agent.status', '2026-08-04 11:58:00', json_encode([
            'agent' => 'sentinela', 'status' => 'failed', 'reason' => 'sentinela_unavailable',
            'correlation_id' => 'agent24x7-20260804T115800Z-aaaaaaaa:1335', 'attempts' => 1, 'state_changed' => false,
            'ml_write_automation' => false,
        ], JSON_THROW_ON_ERROR), 'live']);
        $insert->execute([1335, 'agent.status', '2026-08-04 12:00:00', json_encode([
            'attempts' => 2, 'agent' => 'sentinela', 'correlation_id' => 'agent24x7-20260804T120000Z-bbbbbbbb:1335',
            'ml_write_automation' => false, 'reason' => 'legacy_read_complete', 'state_changed' => false,
            'status' => 'success',
        ], JSON_THROW_ON_ERROR), 'live']);
        $insert->execute([1335, 'agent.status', '2026-08-04 11:40:00', json_encode([
            'agent' => 'financeiro', 'status' => 'failed', 'reason' => 'financeiro_unavailable',
            'correlation_id' => 'agent24x7-20260804T114000Z-cccccccc:1335', 'attempts' => 1, 'state_changed' => false,
            'ml_write_automation' => false,
        ], JSON_THROW_ON_ERROR), 'live']);
        $insert->execute([9999, 'agent.status', '2026-08-04 12:04:00', json_encode([
            'agent' => 'otimizador', 'status' => 'success', 'reason' => 'recommendations_ready',
            'correlation_id' => 'agent24x7-20260804T120400Z-dddddddd:9999', 'attempts' => 1, 'state_changed' => false,
            'ml_write_automation' => false,
        ], JSON_THROW_ON_ERROR), 'live']);
        $insert->execute([1335, 'agent.status', '2026-08-04 12:03:00', json_encode([
            'agent' => 'collector', 'status' => 'success', 'reason' => 'legacy_read_complete',
            'correlation_id' => 'agent24x7-20260804T120300Z-eeeeeeee:1335', 'attempts' => 1, 'state_changed' => false,
            'ml_write_automation' => false, 'unexpected' => 'redacted',
        ], JSON_THROW_ON_ERROR), 'live']);
        $insert->execute([1335, 'agent.status', '2026-08-04 12:04:00', json_encode([
            'agent' => 'collector', 'status' => 'failed', 'reason' => 'collector_unavailable',
            'correlation_id' => 'agent24x7-20260804T120400Z-ffffffff:1335', 'attempts' => 1, 'state_changed' => false,
            'ml_write_automation' => false,
        ], JSON_THROW_ON_ERROR), 'seed']);
        $insert->execute([1335, 'agent.status', '2026-08-04 12:02:00', json_encode([
            'agent' => 'otimizador', 'status' => 'success', 'reason' => 'recommendations_ready',
            'correlation_id' => 'agent24x7-20260804T120200Z-11111111:9999',
            'attempts' => 1, 'state_changed' => false, 'ml_write_automation' => false,
        ], JSON_THROW_ON_ERROR), 'live']);
        $insert->execute([1335, 'agent.status', '2026-08-04 12:02:30', json_encode([
            'agent' => 'orquestrador', 'status' => 'success', 'reason' => 'aggregated',
            'correlation_id' => 'agent24x7-20260804T120230Z-22222222:1335',
            'attempts' => 1, 'state_changed' => true, 'ml_write_automation' => false,
        ], JSON_THROW_ON_ERROR), 'live']);

        $now = new DateTimeImmutable('2026-08-04 12:05:00', new DateTimeZone('America/Sao_Paulo'));
        $result = (new PregaoAgentStatusService($db))->latestForAccount(1335, false, $now);
        $items = array_column($result['items'], null, 'agent');

        self::assertCount(5, $items);
        self::assertSame('success', $items['sentinela']['status']);
        self::assertSame(
            'agent24x7-20260804T120000Z-bbbbbbbb:1335',
            $items['sentinela']['correlation_id']
        );
        self::assertFalse($items['sentinela']['stale']);
        self::assertTrue($items['financeiro']['stale']);
        self::assertSame('waiting', $items['collector']['status']);
        self::assertSame('waiting', $items['otimizador']['status']);
        self::assertSame('waiting', $items['orquestrador']['status']);
        self::assertStringNotContainsString('secret', json_encode($result, JSON_THROW_ON_ERROR));
        self::assertSame('attention', $result['summary']['overall']);
    }

    public function testValidadorRejeitaStatusSuccessComReasonDeFalha(): void
    {
        $payload = [
            'agent' => 'sentinela',
            'status' => 'success',
            'reason' => 'read_only_violation',
            'correlation_id' => 'agent24x7-20260804T120000Z-0123abcd:1335',
            'attempts' => 1,
            'state_changed' => false,
            'ml_write_automation' => false,
        ];

        self::assertNull(PregaoAgentStatusService::validatePayload($payload, 1335));
        self::assertTrue(PregaoAgentStatusService::isStatusReasonCoherent('skipped', 'legacy_read_complete'));
        self::assertTrue(PregaoAgentStatusService::isStatusReasonCoherent('blocked', 'read_only_violation'));
    }

    public function testMesmoTimestampUsaMaiorIdComoDesempateDeterministico(): void
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
        $stmt = $db->prepare(
            'INSERT INTO pregao_events (account_id, type, ts, payload, source) VALUES (?, ?, ?, ?, ?)'
        );
        $base = [
            'agent' => 'sentinela',
            'correlation_id' => 'agent24x7-20260804T120000Z-0123abcd:1335',
            'attempts' => 1,
            'state_changed' => false,
            'ml_write_automation' => false,
        ];
        $stmt->execute([1335, 'agent.status', '2026-08-04 12:00:00', json_encode(
            $base + ['status' => 'failed', 'reason' => 'sentinela_unavailable'],
            JSON_THROW_ON_ERROR
        ), 'live']);
        $stmt->execute([1335, 'agent.status', '2026-08-04 12:00:00', json_encode(
            $base + ['status' => 'success', 'reason' => 'legacy_read_complete'],
            JSON_THROW_ON_ERROR
        ), 'live']);

        $now = new DateTimeImmutable('2026-08-04 12:01:00', new DateTimeZone('America/Sao_Paulo'));
        $items = (new PregaoAgentStatusService($db))->latestForAccount(1335, false, $now)['items'];
        $sentinela = array_values(array_filter(
            $items,
            static fn (array $item): bool => $item['agent'] === 'sentinela'
        ))[0];

        self::assertSame('success', $sentinela['status']);
        self::assertSame('legacy_read_complete', $sentinela['reason']);
    }
}
