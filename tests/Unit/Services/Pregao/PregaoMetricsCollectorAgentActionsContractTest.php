<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Pregao;

use PHPUnit\Framework\TestCase;

final class PregaoMetricsCollectorAgentActionsContractTest extends TestCase
{
    public function testAcoesPorHoraContamSomenteOperacoesReais(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4) . '/app/Services/Pregao/PregaoMetricsCollector.php');
        self::assertIsString($source);
        self::assertStringContainsString("WHERE type = 'op'", $source);
        self::assertStringNotContainsString("type IN ('op', 'agent.status')", $source);
    }
}
