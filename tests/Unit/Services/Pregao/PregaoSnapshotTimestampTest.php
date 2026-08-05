<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Pregao;

use App\Services\Pregao\PregaoSnapshotService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * @covers \App\Services\Pregao\PregaoSnapshotService
 */
final class PregaoSnapshotTimestampTest extends TestCase
{
    private function serviceWithoutDatabase(): PregaoSnapshotService
    {
        return (new ReflectionClass(PregaoSnapshotService::class))->newInstanceWithoutConstructor();
    }

    private function invokePrivate(string $method, string $timestamp): ?string
    {
        $reflection = new ReflectionMethod(PregaoSnapshotService::class, $method);
        $reflection->setAccessible(true);
        return $reflection->invoke($this->serviceWithoutDatabase(), $timestamp);
    }

    public function testMysqlToIsoAceitaDatetime3ComMilissegundos(): void
    {
        self::assertSame(
            '2026-08-04T12:00:00-03:00',
            $this->invokePrivate('mysqlToIso', '2026-08-04 12:00:00.123')
        );
    }

    public function testMysqlToIsoContinuaAceitandoSegundosEMicrossegundos(): void
    {
        self::assertSame(
            '2026-08-04T12:00:00-03:00',
            $this->invokePrivate('mysqlToIso', '2026-08-04 12:00:00')
        );
        self::assertSame(
            '2026-08-04T12:00:00-03:00',
            $this->invokePrivate('mysqlToIso', '2026-08-04 12:00:00.123456')
        );
    }

    public function testMysqlToIsoRejeitaLixoETimestampFuturo(): void
    {
        self::assertNull($this->invokePrivate('mysqlToIso', 'não é data'));
        self::assertNull($this->invokePrivate('mysqlToIso', '2099-01-01 00:00:00.123'));
    }

    public function testMysqlTimestampToIsoInterpretaUtcComMilissegundosEExibeSaoPaulo(): void
    {
        self::assertSame(
            '2026-08-04T09:00:00-03:00',
            $this->invokePrivate('mysqlTimestampToIso', '2026-08-04 12:00:00.123')
        );
        self::assertSame(
            '2026-08-04T09:00:00-03:00',
            $this->invokePrivate('mysqlTimestampToIso', '2026-08-04 12:00:00')
        );
    }

    public function testLoadMetricsSelecionaEpochDoTimestampNaSessaoMySql(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 4) . '/app/Services/Pregao/PregaoSnapshotService.php'
        );
        self::assertIsString($source);
        self::assertStringContainsString(
            'UNIX_TIMESTAMP(updated_at) AS updated_at_epoch',
            $source
        );
        self::assertStringContainsString("\$metrics['updated_at_epoch']", $source);
    }

    public function testSnapshotNaoRecorreAoTimestampTextualQuandoEpochMySqlExisteMasEInvalido(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 4) . '/app/Services/Pregao/PregaoSnapshotService.php'
        );
        self::assertIsString($source);
        $start = strpos($source, "'observability' =>");
        $end = strpos($source, "'semaforo' =>", (int) $start);
        self::assertIsInt($start);
        self::assertIsInt($end);
        $observability = substr($source, $start, $end - $start);

        self::assertStringContainsString(
            "array_key_exists('updated_at_epoch', \$metrics)",
            $observability
        );
    }
}
