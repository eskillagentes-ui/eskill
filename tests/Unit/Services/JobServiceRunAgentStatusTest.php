<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

final class JobServiceRunAgentStatusFakeService
{
    public static int $constructCalls = 0;
    public static int $runCalls = 0;

    public function __construct(?int $accountId = null)
    {
        self::$constructCalls++;
    }

    public function runAgent(string $agentCode): array
    {
        self::$runCalls++;
        return ['success' => true, 'agent' => $agentCode];
    }
}

final class JobServiceRunAgentStatusTest extends TestCase
{
    private const SANDBOX_CLASS = 'Tests\\Unit\\Services\\JobServiceRunAgentStatusSandbox\\JobService';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (class_exists(self::SANDBOX_CLASS, false)) {
            return;
        }

        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Services/JobService.php');
        if (!is_string($source)) {
            throw new \RuntimeException('Não foi possível carregar JobService.php');
        }

        $source = preg_replace('/^<\?php\s*/', '', $source, 1);
        $source = str_replace(
            'namespace App\\Services;',
            'namespace Tests\\Unit\\Services\\JobServiceRunAgentStatusSandbox;',
            (string)$source
        );
        $source = str_replace(
            'use App\\Services\\AutonomousAgentService;',
            'use Tests\\Unit\\Services\\JobServiceRunAgentStatusFakeService as AutonomousAgentService;',
            $source
        );

        // O código avaliado é uma cópia fixa do arquivo versionado, com apenas namespace/import trocados
        // para injetar o double sem banco real nem alteração do código de produção.
        eval($source);
    }

    protected function setUp(): void
    {
        parent::setUp();
        JobServiceRunAgentStatusFakeService::$constructCalls = 0;
        JobServiceRunAgentStatusFakeService::$runCalls = 0;
    }

    public function testActiveStatusRunsAutonomousAgent(): void
    {
        [$service, $method] = $this->serviceWithStatus('active', 'sniper');

        $result = $method->invoke($service, ['agent' => 'sniper', 'account_id' => 1335]);

        $this->assertTrue($result['success']);
        $this->assertSame('sniper', $result['agent']);
        $this->assertSame(1, JobServiceRunAgentStatusFakeService::$constructCalls);
        $this->assertSame(1, JobServiceRunAgentStatusFakeService::$runCalls);
    }

    public function testPausedStatusReturnsSkippedWithoutInstantiatingService(): void
    {
        [$service, $method] = $this->serviceWithStatus('paused', 'sniper');

        $result = $method->invoke($service, ['agent' => 'sniper']);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['skipped']);
        $this->assertSame('paused', $result['status']);
        $this->assertStringContainsString('status=paused', $result['error']);
        $this->assertSame(0, JobServiceRunAgentStatusFakeService::$constructCalls);
        $this->assertSame(0, JobServiceRunAgentStatusFakeService::$runCalls);
    }

    public function testMissingCodeReturnsNotFoundWithoutInstantiatingService(): void
    {
        [$service, $method] = $this->serviceWithStatus(false, 'inexistente');

        $result = $method->invoke($service, ['agent' => 'inexistente']);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['skipped']);
        $this->assertSame('not_found', $result['status']);
        $this->assertStringContainsString('status=not_found', $result['error']);
        $this->assertSame(0, JobServiceRunAgentStatusFakeService::$constructCalls);
        $this->assertSame(0, JobServiceRunAgentStatusFakeService::$runCalls);
    }

    /**
     * @return array{0: object, 1: ReflectionMethod}
     */
    private function serviceWithStatus(string|false $status, string $expectedCode): array
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->expects($this->once())
            ->method('execute')
            ->with([':code' => $expectedCode])
            ->willReturn(true);
        $statement->expects($this->once())
            ->method('fetchColumn')
            ->willReturn($status);

        $db = $this->createMock(PDO::class);
        $db->expects($this->once())
            ->method('prepare')
            ->with('SELECT status FROM ai_agents WHERE code = :code LIMIT 1')
            ->willReturn($statement);

        $reflection = new ReflectionClass(self::SANDBOX_CLASS);
        $service = $reflection->newInstanceWithoutConstructor();

        $dbProperty = new ReflectionProperty(self::SANDBOX_CLASS, 'db');
        $dbProperty->setAccessible(true);
        $dbProperty->setValue($service, $db);

        $method = new ReflectionMethod(self::SANDBOX_CLASS, 'runAutonomousAgentJob');
        $method->setAccessible(true);

        return [$service, $method];
    }
}
