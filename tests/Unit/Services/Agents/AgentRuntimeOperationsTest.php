<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agents;

use App\Services\Agents\AgentRuntimeAccountSource;
use App\Services\Agents\AgentRuntimeExecutor;
use App\Services\Agents\AgentRuntimeFactory;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * @covers \App\Services\Agents\AgentRuntimeAccountSource
 * @covers \App\Services\Agents\AgentRuntimeExecutor
 */
final class AgentRuntimeOperationsTest extends TestCase
{
    public function testFonteRetornaSomenteContasAtivasOrdenadas(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE ml_accounts (id INTEGER PRIMARY KEY, status TEXT NULL)');
        $pdo->exec("INSERT INTO ml_accounts (id, status) VALUES (20, 'active'), (10, 'active'), (30, 'connected'), (40, NULL)");

        self::assertSame([10, 20], (new AgentRuntimeAccountSource($pdo))->activeAccountIds());
    }

    public function testFonteFalhaFechadaQuandoExistemMaisDeDuzentasContasAtivas(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE ml_accounts (id INTEGER PRIMARY KEY, status TEXT NULL)');
        $statement = $pdo->prepare("INSERT INTO ml_accounts (id, status) VALUES (:id, 'active')");
        for ($id = 1; $id <= 201; $id++) {
            $statement->execute(['id' => $id]);
        }

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('account source limit exceeded');

        (new AgentRuntimeAccountSource($pdo))->activeAccountIds();
    }

    public function testFonteProductionContemSomenteSelectParametrizadoOuConstante(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4) . '/app/Services/Agents/AgentRuntimeAccountSource.php');
        self::assertIsString($source);
        self::assertSame(1, preg_match_all('/\bSELECT\b/i', $source));
        foreach (['INSERT', 'UPDATE', 'DELETE', 'CREATE', 'ALTER', 'DROP', '->exec(', '->query('] as $forbidden) {
            self::assertDoesNotMatchRegularExpression('/\b' . preg_quote($forbidden, '/') . '\b/i', $source);
        }
    }

    public function testExecutorRodaRosterMonitorSemAgentesSobDemanda(): void
    {
        $factory = new AgentRuntimeFactory(new AgentRuntimeReadGatewayFake());
        $result = (new AgentRuntimeExecutor($factory))->execute(10, 'cycle-exec:10', 'production', 'monitor');

        self::assertSame('success', $result->status());
        self::assertSame(
            ['sentinela', 'coletor', 'financeiro', 'otimizador'],
            array_map(static fn ($agentResult): string => $agentResult->agent(), $result->data()['results'])
        );
    }

    public function testExecutorBloqueiaModoForaDeMonitor(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new AgentRuntimeExecutor(new AgentRuntimeFactory(new AgentRuntimeReadGatewayFake())))
            ->execute(10, 'cycle-exec:10', 'production', 'all');
    }
}
