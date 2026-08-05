<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Pregao;

use App\Services\Pregao\AccountIndexCalculator;
use App\Services\Pregao\PregaoSnapshotService;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Services\Pregao\PregaoSnapshotService
 */
final class PregaoSnapshotDailyIndexTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec(
            'CREATE TABLE account_index_metrics (
                account_id INTEGER PRIMARY KEY,
                vendas_7d REAL,
                visitas_7d REAL,
                health_medio REAL,
                reputacao_cor TEXT,
                tacos REAL,
                indice_atual REAL,
                metrics_meta TEXT,
                updated_at TEXT DEFAULT \'2026-08-04 15:00:00\'
            )'
        );
        $this->db->exec(
            'CREATE TABLE account_index_baselines (
                account_id INTEGER PRIMARY KEY,
                vendas_7d_baseline REAL,
                pos_baseline REAL,
                visitas_baseline REAL,
                tacos_baseline REAL
            )'
        );
        $this->db->exec(
            'CREATE TABLE account_index_daily (
                account_id INTEGER,
                `date` TEXT,
                o REAL,
                h REAL,
                l REAL,
                c REAL,
                updated_at TEXT DEFAULT \'2026-08-04 15:00:00\'
            )'
        );
        $this->db->exec(
            'CREATE TABLE pregao_events (
                account_id INTEGER,
                type TEXT,
                ts TEXT,
                payload TEXT
            )'
        );
    }

    public function testVariacaoEExtremosUsamSomenteCandleDoDiaAtual(): void
    {
        $accountId = 77;
        $timezone = new DateTimeZone('America/Sao_Paulo');
        $today = new DateTimeImmutable('today', $timezone);
        $oldDate = $today->modify('-89 days')->format('Y-m-d');
        $todayDate = $today->format('Y-m-d');
        $meta = json_encode([
            'available' => ['Fv' => true],
            'metrics' => ['vendas_7d' => ['available' => true]],
        ], JSON_THROW_ON_ERROR);

        $stmt = $this->db->prepare(
            'INSERT INTO account_index_metrics
             (account_id, vendas_7d, visitas_7d, health_medio, reputacao_cor, tacos, indice_atual, metrics_meta)
             VALUES (?, ?, 0, 0, NULL, NULL, NULL, ?)'
        );
        $stmt->execute([$accountId, 11.0, $meta]);
        $this->db->prepare(
            'INSERT INTO account_index_baselines
             (account_id, vendas_7d_baseline, pos_baseline, visitas_baseline, tacos_baseline)
             VALUES (?, 10, 10, 1, 10)'
        )->execute([$accountId]);
        $insertCandle = $this->db->prepare(
            'INSERT INTO account_index_daily (account_id, `date`, o, h, l, c) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $insertCandle->execute([$accountId, $oldDate, 800, 1200, 700, 850]);
        $insertCandle->execute([$accountId, $todayDate, 1000, 1150, 950, 1100]);

        $service = new PregaoSnapshotService(
            $this->db,
            new AccountIndexCalculator(),
            ['seed_enabled' => false, 'rank_tracker_enabled' => false]
        );
        $snapshot = $service->getSnapshot($accountId);

        $this->assertSame(1000.0, $snapshot['index']['open']);
        $this->assertSame(10.0, $snapshot['index']['change_pct']);
        $live = (float) $snapshot['index']['value'];
        $this->assertNotNull($snapshot['index']['value']);
        // high/low do dia = extremos do candle ∪ índice live
        $this->assertSame(max(1150.0, $live), (float) $snapshot['index']['high']);
        $this->assertSame(min(950.0, $live), (float) $snapshot['index']['low']);
        $this->assertArrayHasKey('agents', $snapshot);
        $this->assertCount(5, $snapshot['agents']['items']);
        $this->assertArrayHasKey('observability', $snapshot);
        $this->assertTrue($snapshot['observability']['read_only']);
        $this->assertCount(8, $snapshot['observability']['items']);
        self::assertSame('2026-08-04T12:00:00-03:00', $snapshot['index']['updated_at']);
        self::assertSame('2026-08-04T12:00:00-03:00', $snapshot['candles'][0]['updated_at']);
    }

    public function testVariacaoDiariaFalhaFechadoComAberturaZero(): void
    {
        $accountId = 78;
        $todayDate = new DateTimeImmutable('today', new DateTimeZone('America/Sao_Paulo'));
        $today = $todayDate->format('Y-m-d');
        $oldDate = $todayDate->modify('-1 day')->format('Y-m-d');
        $meta = json_encode([
            'available' => ['Fv' => true],
            'metrics' => ['vendas_7d' => ['available' => true]],
        ], JSON_THROW_ON_ERROR);

        $this->db->prepare(
            'INSERT INTO account_index_metrics
             (account_id, vendas_7d, visitas_7d, health_medio, reputacao_cor, tacos, indice_atual, metrics_meta)
             VALUES (?, 11, 0, 0, NULL, NULL, NULL, ?)'
        )->execute([$accountId, $meta]);
        $this->db->prepare(
            'INSERT INTO account_index_baselines
             (account_id, vendas_7d_baseline, pos_baseline, visitas_baseline, tacos_baseline)
             VALUES (?, 10, 10, 1, 10)'
        )->execute([$accountId]);
        $insertCandle = $this->db->prepare(
            'INSERT INTO account_index_daily (account_id, `date`, o, h, l, c) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $insertCandle->execute([$accountId, $oldDate, 800, 900, 700, 850]);
        $insertCandle->execute([$accountId, $today, 0, 1100, 0, 1100]);

        $service = new PregaoSnapshotService(
            $this->db,
            new AccountIndexCalculator(),
            ['seed_enabled' => false, 'rank_tracker_enabled' => false]
        );
        $snapshot = $service->getSnapshot($accountId);

        $this->assertNull($snapshot['index']['change_pct']);
    }

    public function testIndiceLiveSemCandleAtualNaoInventaExtremosDiarios(): void
    {
        $accountId = 79;
        $meta = json_encode([
            'available' => ['Fv' => true],
            'metrics' => ['vendas_7d' => ['available' => true]],
        ], JSON_THROW_ON_ERROR);
        $this->db->prepare(
            'INSERT INTO account_index_metrics
             (account_id, vendas_7d, visitas_7d, health_medio, reputacao_cor, tacos, indice_atual, metrics_meta)
             VALUES (?, 11, 0, 0, NULL, NULL, NULL, ?)'
        )->execute([$accountId, $meta]);
        $this->db->prepare(
            'INSERT INTO account_index_baselines
             (account_id, vendas_7d_baseline, pos_baseline, visitas_baseline, tacos_baseline)
             VALUES (?, 10, 10, 1, 10)'
        )->execute([$accountId]);
        $oldDate = (new DateTimeImmutable('today', new DateTimeZone('America/Sao_Paulo')))
            ->modify('-1 day')
            ->format('Y-m-d');
        $this->db->prepare(
            'INSERT INTO account_index_daily (account_id, `date`, o, h, l, c) VALUES (?, ?, 800, 900, 700, 850)'
        )->execute([$accountId, $oldDate]);

        $snapshot = (new PregaoSnapshotService(
            $this->db,
            new AccountIndexCalculator(),
            ['seed_enabled' => false, 'rank_tracker_enabled' => false]
        ))->getSnapshot($accountId);

        self::assertNotNull($snapshot['index']['value']);
        self::assertNull($snapshot['index']['open']);
        self::assertNull($snapshot['index']['change_pct']);
        self::assertNull($snapshot['index']['high']);
        self::assertNull($snapshot['index']['low']);
    }

    public function testSnapshotNaoIncluiEventoGlobalLegado(): void
    {
        $insert = $this->db->prepare(
            'INSERT INTO pregao_events (account_id, type, ts, payload) VALUES (?, ?, ?, ?)'
        );
        $insert->execute([null, 'op', '2026-08-04 12:00:00', json_encode([
            'robot' => 'GLOBAL', 'msg' => 'não deve vazar',
        ], JSON_THROW_ON_ERROR)]);
        $insert->execute([77, 'op', '2026-08-04 12:01:00', json_encode([
            'robot' => 'CONTA', 'msg' => 'permitido',
        ], JSON_THROW_ON_ERROR)]);

        $snapshot = (new PregaoSnapshotService(
            $this->db,
            new AccountIndexCalculator(),
            ['seed_enabled' => false, 'rank_tracker_enabled' => false]
        ))->getSnapshot(77);

        self::assertCount(1, $snapshot['operations']);
        self::assertSame('CONTA', $snapshot['operations'][0]['payload']['robot']);
        $source = file_get_contents(dirname(__DIR__, 4) . '/app/Services/Pregao/PregaoSnapshotService.php');
        self::assertIsString($source);
        self::assertStringNotContainsString('account_id IS NULL', $source);
    }

    public function testWatermarksComDataImpossivelFalhamFechado(): void
    {
        $meta = json_encode([
            'available' => ['Fv' => true],
            'metrics' => ['vendas_7d' => ['available' => true]],
        ], JSON_THROW_ON_ERROR);
        $this->db->prepare(
            'INSERT INTO account_index_metrics
             (account_id, vendas_7d, visitas_7d, health_medio, reputacao_cor, tacos, indice_atual, metrics_meta, updated_at)
             VALUES (81, 11, 0, 0, NULL, NULL, NULL, ?, ?)'
        )->execute([$meta, '2026-02-30 12:00:00']);
        $this->db->prepare(
            'INSERT INTO account_index_daily (account_id, `date`, o, h, l, c, updated_at)
             VALUES (81, ?, 100, 110, 90, 105, ?)'
        )->execute([
            (new DateTimeImmutable('today', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d'),
            '2026-02-30 12:00:00',
        ]);

        $snapshot = (new PregaoSnapshotService(
            $this->db,
            new AccountIndexCalculator(),
            ['seed_enabled' => false, 'rank_tracker_enabled' => false]
        ))->getSnapshot(81);

        self::assertNull($snapshot['index']['updated_at']);
        self::assertNull($snapshot['candles'][0]['updated_at']);
    }

    public function testWatermarksFuturosFalhamFechado(): void
    {
        $meta = json_encode([
            'available' => ['Fv' => true],
            'metrics' => ['vendas_7d' => ['available' => true]],
        ], JSON_THROW_ON_ERROR);
        $this->db->prepare(
            'INSERT INTO account_index_metrics
             (account_id, vendas_7d, visitas_7d, health_medio, reputacao_cor, tacos, indice_atual, metrics_meta, updated_at)
             VALUES (82, 11, 0, 0, NULL, NULL, NULL, ?, ?)'
        )->execute([$meta, '2099-01-01 00:00:00']);
        $this->db->prepare(
            'INSERT INTO account_index_daily (account_id, `date`, o, h, l, c, updated_at)
             VALUES (82, ?, 100, 110, 90, 105, ?)'
        )->execute([
            (new DateTimeImmutable('today', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d'),
            '2099-01-01 00:00:00',
        ]);

        $snapshot = (new PregaoSnapshotService(
            $this->db,
            new AccountIndexCalculator(),
            ['seed_enabled' => false, 'rank_tracker_enabled' => false]
        ))->getSnapshot(82);

        self::assertNull($snapshot['index']['updated_at']);
        self::assertNull($snapshot['candles'][0]['updated_at']);
    }

    public function testColetorDeAcoesNaoIncluiEventosGlobaisLegados(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4) . '/app/Services/Pregao/PregaoMetricsCollector.php');
        self::assertIsString($source);
        self::assertStringNotContainsString('account_id = ? OR account_id IS NULL', $source);
    }
}
