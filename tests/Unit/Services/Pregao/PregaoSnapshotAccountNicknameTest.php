<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Pregao;

use App\Services\Pregao\PregaoSnapshotService;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * @covers \App\Services\Pregao\PregaoSnapshotService
 */
final class PregaoSnapshotAccountNicknameTest extends TestCase
{
    private function makeDb(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE ml_accounts (id INTEGER PRIMARY KEY, nickname TEXT)');
        return $db;
    }

    private function loadNickname(PDO $db, int $accountId): ?string
    {
        $service = (new ReflectionClass(PregaoSnapshotService::class))->newInstanceWithoutConstructor();
        $property = new \ReflectionProperty(PregaoSnapshotService::class, 'db');
        $property->setAccessible(true);
        $property->setValue($service, $db);

        $method = new ReflectionMethod(PregaoSnapshotService::class, 'loadAccountNickname');
        $method->setAccessible(true);
        return $method->invoke($service, $accountId);
    }

    public function testRetornaNicknameRealDaContaQuandoPersistido(): void
    {
        $db = $this->makeDb();
        $db->exec("INSERT INTO ml_accounts (id, nickname) VALUES (1335, 'AWAMOTOS')");

        self::assertSame('AWAMOTOS', $this->loadNickname($db, 1335));
    }

    public function testRetornaNullQuandoContaInexistente(): void
    {
        $db = $this->makeDb();

        self::assertNull($this->loadNickname($db, 9999));
    }

    public function testRetornaNullQuandoNicknameVazioOuNuloNuncaInventaTexto(): void
    {
        $db = $this->makeDb();
        $db->exec("INSERT INTO ml_accounts (id, nickname) VALUES (1335, '')");
        $db->exec('INSERT INTO ml_accounts (id, nickname) VALUES (2000, NULL)');

        self::assertNull($this->loadNickname($db, 1335));
        self::assertNull($this->loadNickname($db, 2000));
    }
}
