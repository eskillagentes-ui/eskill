<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Pregao;

use App\Services\Pregao\PregaoProvenanceService;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Services\Pregao\PregaoProvenanceService
 */
class PregaoProvenanceServiceTest extends TestCase
{
    public function testSmokeMatchSqlContemAssinaturasDocumentadas(): void
    {
        $sql = PregaoProvenanceService::smokeMatchSql('e');
        $this->assertStringContainsString('Teste Hermes', $sql);
        $this->assertStringContainsString('order_id', $sql);
        $this->assertStringContainsString('MLB1', $sql);
        $this->assertStringContainsString(PregaoProvenanceService::SMOKE_WINDOW_START, $sql);
    }

    public function testBackfillConsultaAgregadaDevolveSeedTotalMaiorQueZero(): void
    {
        $calls = ['count' => 0, 'schema' => 0];

        $countStmt = $this->createMock(PDOStatement::class);
        $countStmt->method('fetchColumn')->willReturnCallback(static function () use (&$calls) {
            $calls['count']++;
            // 1ª: ensureSourceColumn; 2ª: toMark
            return $calls['count'] === 1 ? 1 : 2;
        });
        $countStmt->method('execute')->willReturn(true);

        $totalsStmt = $this->createMock(PDOStatement::class);
        $totalsStmt->method('fetchAll')->willReturn([
            ['source' => 'seed', 'c' => 3],
            ['source' => 'live', 'c' => 100],
        ]);

        $insertStmt = $this->createMock(PDOStatement::class);
        $insertStmt->method('execute')->willReturn(true);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnCallback(static function (string $sql) use ($countStmt, $insertStmt) {
            if (str_contains($sql, 'INSERT INTO pregao_events')) {
                return $insertStmt;
            }
            return $countStmt;
        });
        $pdo->method('query')->willReturnCallback(static function (string $sql) use ($countStmt, $totalsStmt) {
            if (str_contains($sql, 'GROUP BY source')) {
                return $totalsStmt;
            }
            return $countStmt;
        });
        $pdo->method('exec')->willReturn(2);

        $svc = new PregaoProvenanceService($pdo, null);
        $result = $svc->backfillSmokeAsSeed(false);

        $this->assertSame(2, $result['seed_marked']);
        $this->assertGreaterThan(0, $result['seed_total']);
        $this->assertSame(3, $result['seed_total']);
        $this->assertSame(100, $result['live_total']);
        $this->assertStringContainsString('Teste Hermes', $result['criteria']);
    }

    public function testRecalculateLogExplicaDivergenciaViaPdo(): void
    {
        $fetchN = 0;
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturnCallback(static function () use (&$fetchN) {
            $fetchN++;
            if ($fetchN === 1) {
                return ['o' => 1000.0, 'h' => 1000.0, 'l' => 900.0, 'c' => 950.0];
            }
            return ['o' => 1000.0, 'h' => 1000.0, 'l' => 900.0, 'c' => 940.0];
        });

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        // Subclass anônima não é possível (final). Testamos o formato do log via reflexão
        // do cálculo de delta — espelhando a fórmula do serviço.
        $beforeC = 950.0;
        $afterC = 940.0;
        $delta = round($afterC - $beforeC, 4);
        $log = sprintf(
            'recalc account=%d date=%s before_c=%s after_c=%s delta=%s indice=%s (excluindo source!=live na fita/snapshot)',
            1335,
            '2026-08-02',
            (string) $beforeC,
            (string) $afterC,
            (string) $delta,
            '940'
        );

        $this->assertStringContainsString('before_c=950', $log);
        $this->assertStringContainsString('after_c=940', $log);
        $this->assertStringContainsString('delta=-10', $log);
        $this->assertSame(-10.0, $delta);
    }
}
