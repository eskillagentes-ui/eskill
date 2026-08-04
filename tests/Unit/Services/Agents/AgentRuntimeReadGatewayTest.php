<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agents;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

/** @covers \App\Services\Agents\AgentRuntimeReadGateway */
final class AgentRuntimeReadGatewayTest extends TestCase
{
    public function testExpoeSomenteGatewayEstreitoReadOnlyFinal(): void
    {
        $interface = 'App\Services\Agents\AgentRuntimeReadGatewayInterface';
        $implementation = 'App\Services\Agents\AgentRuntimeReadGateway';

        self::assertTrue(interface_exists($interface), 'interface estreita ausente');
        self::assertTrue(class_exists($implementation), 'gateway production ausente');

        $reflection = new ReflectionClass($implementation);
        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->implementsInterface($interface));
        self::assertSame(0, $reflection->getConstructor()?->getNumberOfParameters() ?? 0);
        foreach ([
            'sentinelaDashboard',
            'adsDashboard',
            'financialDashboardSummary',
            'financialMetrics',
            'skuCostByMlb',
            'item',
        ] as $method) {
            self::assertContains($method, get_class_methods($implementation));
        }
    }

    public function testImplementacaoProductionFixaContaEGetItemSemCacheLocal(): void
    {
        $path = __DIR__ . '/../../../../app/Services/Agents/AgentRuntimeReadGateway.php';
        $source = file_get_contents($path);
        self::assertIsString($source);
        self::assertStringContainsString('new FinancialService($accountId)', $source);
        self::assertStringContainsString("['allow_local_cache' => false]", $source);
        self::assertStringContainsString('->getByMlb($accountId, $mlbId)', $source);
        self::assertStringNotContainsString('function __construct(', $source);
        foreach (['createRefund', 'updateItem', 'post(', 'put(', 'delete('] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $source);
        }
    }
}
