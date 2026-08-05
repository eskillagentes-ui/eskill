<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Pregao;

use App\Services\Pregao\PregaoMetricsCollector;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Freshness por fonte: o coletor deve persistir o horário real da coleta
 * (collected_at, epoch — mesmo contrato do Ads) em metrics_meta para
 * vendas, visitas, reputação e perguntas. O dashboard apenas lê esse valor;
 * nenhum collector roda durante a leitura do snapshot.
 */
final class PregaoMetricsCollectorFreshnessContractTest extends TestCase
{
    private function methodWindow(string $source, string $start, string $end): string
    {
        $startPos = strpos($source, $start);
        $endPos = strpos($source, $end, (int) $startPos);
        self::assertIsInt($startPos, "método {$start} deve existir");
        self::assertIsInt($endPos, "método {$end} deve existir");
        return substr($source, $startPos, $endPos - $startPos);
    }

    public function testColetorPersisteCollectedAtRealParaVendasVisitasReputacaoEPerguntas(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 4) . '/app/Services/Pregao/PregaoMetricsCollector.php'
        );
        self::assertIsString($source);

        $windows = [
            'vendas' => $this->methodWindow(
                $source,
                'private function collectSales',
                'private function collectVisits'
            ),
            'visitas' => $this->methodWindow(
                $source,
                'private function collectVisits',
                'private function fetchAccountVisits'
            ),
            'reputacao' => $this->methodWindow(
                $source,
                'private function collectReputation',
                'private function collectHealth'
            ),
            'perguntas' => $this->methodWindow(
                $source,
                'private function collectQuestions',
                'private function collectSales'
            ),
        ];

        foreach ($windows as $label => $window) {
            self::assertStringContainsString(
                '$collectedAt = time();',
                $window,
                "{$label}: horário real da coleta deve vir de time(), nunca inventado"
            );
            self::assertStringContainsString(
                "'collected_at' => \$collectedAt",
                $window,
                "{$label}: meta de sucesso deve persistir collected_at"
            );
        }

        foreach (['vendas_hoje', 'visitas_7d', 'reputacao', 'perguntas_7d'] as $metaKey) {
            self::assertMatchesRegularExpression(
                '/\'' . preg_quote($metaKey, '/') . '\'\] = \[\s*\'available\' => true,[^;]*\'collected_at\' => \$collectedAt/s',
                $source,
                "meta {$metaKey} disponível deve carregar collected_at"
            );
        }
    }

    public function testHealthConverteDatetimePersistidoParaEpochNaPropriaSessaoMySql(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 4) . '/app/Services/Pregao/PregaoMetricsCollector.php'
        );
        self::assertIsString($source);
        $window = $this->methodWindow(
            $source,
            'private function collectHealth',
            'private function collectQuestions'
        );

        self::assertStringContainsString("'mysql' => 'UNIX_TIMESTAMP(created_at)'", $source);
        self::assertStringContainsString('$this->healthTimestampExpression()', $window);
        self::assertStringContainsString("'collected_at' => (int) \$row['created_at_epoch']", $window);
        self::assertStringNotContainsString("'as_of' =>", $window);
    }

    public function testExpressaoDeEpochHealthEhExecutavelNoSqlite(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE account_health_history (created_at TEXT NOT NULL)');
        $db->exec("INSERT INTO account_health_history (created_at) VALUES ('2026-08-04 12:00:00')");

        $collector = new PregaoMetricsCollector($db, null, null, []);
        $method = new ReflectionMethod(PregaoMetricsCollector::class, 'healthTimestampExpression');
        $method->setAccessible(true);
        $expression = $method->invoke($collector);

        self::assertSame("CAST(strftime('%s', created_at) AS INTEGER)", $expression);
        $epoch = $db->query("SELECT {$expression} FROM account_health_history")->fetchColumn();
        self::assertIsNumeric($epoch);
        self::assertGreaterThan(0, (int) $epoch);
    }

    public function testColetaHealthCompletaPersisteFreshnessNoSqlite(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec(
            'CREATE TABLE account_health_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                account_id INTEGER NOT NULL,
                overall_score REAL NOT NULL,
                created_at TEXT NOT NULL
            )'
        );
        $db->exec('CREATE TABLE account_index_metrics (account_id INTEGER PRIMARY KEY, health_medio REAL)');
        $db->exec(
            'CREATE TABLE pregao_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                account_id INTEGER,
                type TEXT,
                ts TEXT,
                payload TEXT
            )'
        );
        $db->exec(
            "INSERT INTO account_health_history (account_id, overall_score, created_at)
             VALUES (1335, 82, '2026-08-04 12:00:00')"
        );

        $collector = new PregaoMetricsCollector($db, null, null, []);
        $method = new ReflectionMethod(PregaoMetricsCollector::class, 'collectHealth');
        $method->setAccessible(true);
        $meta = ['available' => [], 'metrics' => []];
        $result = $method->invokeArgs($collector, [1335, &$meta]);

        self::assertTrue($result['ok']);
        self::assertSame(0.82, $result['health_medio']);
        self::assertTrue($meta['available']['Fh']);
        self::assertGreaterThan(0, $meta['metrics']['health_medio']['collected_at']);
        self::assertSame(0.82, (float) $db->query(
            'SELECT health_medio FROM account_index_metrics WHERE account_id = 1335'
        )->fetchColumn());
    }
}
