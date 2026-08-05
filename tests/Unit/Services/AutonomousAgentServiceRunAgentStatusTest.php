<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

final class AutonomousAgentServiceRunAgentStatusFakeAgent
{
    public static int $constructCalls = 0;
    public static int $executeCalls = 0;

    public function __construct()
    {
        self::$constructCalls++;
    }

    public function execute(): array
    {
        self::$executeCalls++;
        return ['executed' => true];
    }
}

final class AutonomousAgentServiceRunAgentStatusTest extends TestCase
{
    private const SANDBOX_CLASS = 'Tests\\Unit\\Services\\AutonomousAgentServiceRunAgentStatusSandbox\\AutonomousAgentService';
    private const STATUS_SQL = 'SELECT status FROM ai_agents WHERE code = :code LIMIT 1';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (class_exists(self::SANDBOX_CLASS, false)) {
            return;
        }

        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Services/AutonomousAgentService.php');
        if (!is_string($source)) {
            throw new \RuntimeException('Não foi possível carregar AutonomousAgentService.php');
        }

        $source = preg_replace('/^<\?php\s*/', '', $source, 1);
        $source = str_replace(
            'namespace App\\Services;',
            'namespace Tests\\Unit\\Services\\AutonomousAgentServiceRunAgentStatusSandbox;',
            (string)$source
        );
        $source = str_replace(
            '\\App\\Agents\\GuardianAgent::class',
            '\\Tests\\Unit\\Services\\AutonomousAgentServiceRunAgentStatusFakeAgent::class',
            $source
        );
        $source = str_replace(
            '\\App\\Agents\\SniperAgent::class',
            '\\Tests\\Unit\\Services\\AutonomousAgentServiceRunAgentStatusFakeAgent::class',
            $source
        );

        // O código avaliado é uma cópia fixa do arquivo versionado, com namespace e mapa de agents
        // redirecionados para um double isolado; nenhuma entrada externa é avaliada.
        eval($source);
    }

    protected function setUp(): void
    {
        parent::setUp();
        AutonomousAgentServiceRunAgentStatusFakeAgent::$constructCalls = 0;
        AutonomousAgentServiceRunAgentStatusFakeAgent::$executeCalls = 0;
    }

    public function testActiveStatusRunsResolvedAgent(): void
    {
        $service = $this->serviceWithStatus('active', 'guardian');

        $result = $service->runAgent('guardian');

        $this->assertTrue($result['success']);
        $this->assertSame(['executed' => true], $result['result']);
        $this->assertSame(1, AutonomousAgentServiceRunAgentStatusFakeAgent::$constructCalls);
        $this->assertSame(1, AutonomousAgentServiceRunAgentStatusFakeAgent::$executeCalls);
    }

    public function testPausedStatusReturnsSkippedWithoutInstantiatingAgent(): void
    {
        $service = $this->serviceWithStatus('paused', 'sniper');

        $result = $service->runAgent('sniper');

        $this->assertFalse($result['success']);
        $this->assertTrue($result['skipped']);
        $this->assertSame('paused', $result['status']);
        $this->assertSame('sniper', $result['agent']);
        $this->assertStringContainsString('status=paused', $result['error']);
        $this->assertSame(0, AutonomousAgentServiceRunAgentStatusFakeAgent::$constructCalls);
        $this->assertSame(0, AutonomousAgentServiceRunAgentStatusFakeAgent::$executeCalls);
    }

    public function testMissingStatusRowReturnsNotFoundWithoutInstantiatingAgent(): void
    {
        $service = $this->serviceWithStatus(false, 'sniper');

        $result = $service->runAgent('sniper');

        $this->assertFalse($result['success']);
        $this->assertTrue($result['skipped']);
        $this->assertSame('not_found', $result['status']);
        $this->assertSame('sniper', $result['agent']);
        $this->assertStringContainsString('status=not_found', $result['error']);
        $this->assertSame(0, AutonomousAgentServiceRunAgentStatusFakeAgent::$constructCalls);
        $this->assertSame(0, AutonomousAgentServiceRunAgentStatusFakeAgent::$executeCalls);
    }

    private function serviceWithStatus(string|false $status, string $expectedCode): object
    {
        $statusStatement = $this->createMock(PDOStatement::class);
        $statusStatement->expects($this->once())
            ->method('execute')
            ->with([':code' => $expectedCode])
            ->willReturn(true);
        $statusStatement->expects($this->once())
            ->method('fetchColumn')
            ->willReturn($status);

        $logStatement = $this->createMock(PDOStatement::class);
        if ($status === 'active') {
            $logStatement->expects($this->once())
                ->method('execute')
                ->with($this->callback(static function (array $parameters): bool {
                    return $parameters['action'] === 'agent_run:guardian:success';
                }))
                ->willReturn(true);
        }

        $db = $this->createMock(PDO::class);
        $db->method('prepare')
            ->willReturnCallback(function (string $sql) use ($statusStatement, $logStatement): PDOStatement {
                if ($sql === self::STATUS_SQL) {
                    return $statusStatement;
                }

                if (str_contains($sql, 'INSERT INTO agent_progress_log')) {
                    return $logStatement;
                }

                self::fail("SQL inesperado no teste: {$sql}");
            });

        $reflection = new ReflectionClass(self::SANDBOX_CLASS);
        $service = $reflection->newInstanceWithoutConstructor();

        $dbProperty = new ReflectionProperty(self::SANDBOX_CLASS, 'db');
        $dbProperty->setAccessible(true);
        $dbProperty->setValue($service, $db);

        return $service;
    }
}
