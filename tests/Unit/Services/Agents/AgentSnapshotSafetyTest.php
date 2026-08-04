<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agents;

use App\Services\Agents\AgentContext;
use App\Services\Agents\AgentResult;
use App\Services\Agents\CollectorAgent;
use App\Services\Agents\CriadorAgent;
use App\Services\Agents\FinanceiroAgent;
use App\Services\Agents\OtimizadorAgent;
use App\Services\Agents\QaAgent;
use App\Services\Agents\SentinelaAgent;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use stdClass;

/** @covers \App\Services\Agents\AgentSnapshotSafety */
final class AgentSnapshotSafetyTest extends TestCase
{
    /** @dataProvider agentClasses */
    public function testAgentesNaoAceitamConstructorArgs(string $class): void
    {
        $ref = new ReflectionClass($class);
        $ctor = $ref->getConstructor();
        $this->assertNotNull($ctor);
        $this->assertSame(0, $ctor->getNumberOfParameters());
        $this->expectException(\InvalidArgumentException::class);
        new $class(new stdClass());
    }

    /** @return iterable<string, array{0: class-string}> */
    public function agentClasses(): iterable
    {
        yield 'sentinela' => [SentinelaAgent::class];
        yield 'collector' => [CollectorAgent::class];
        yield 'financeiro' => [FinanceiroAgent::class];
        yield 'criador' => [CriadorAgent::class];
        yield 'otimizador' => [OtimizadorAgent::class];
        yield 'qa' => [QaAgent::class];
    }

    public function testFontesNaoContemCallableCapability(): void
    {
        $files = [
            SentinelaAgent::class,
            CollectorAgent::class,
            FinanceiroAgent::class,
            CriadorAgent::class,
            OtimizadorAgent::class,
            QaAgent::class,
        ];
        foreach ($files as $class) {
            $path = (new ReflectionClass($class))->getFileName();
            $this->assertNotFalse($path);
            $source = (string) file_get_contents($path);
            $this->assertDoesNotMatchRegularExpression(
                '/\\bcallable\\b|\\bClosure\\b|fromCallable|PDO|MercadoLivreClient/',
                $source,
                $class
            );
        }
    }

    public function testContextRejeitaObjetoAninhado(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AgentContext(1, 'local', 'c', false, ['x' => ['y' => new stdClass()]]);
    }

    public function testContextRejeitaClosureAninhada(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AgentContext(1, 'local', 'c', false, ['x' => ['y' => static function (): void {
        }]]);
    }

    public function testContextRejeitaAgentResultForaDeQa(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AgentContext(1, 'local', 'c', false, [
            'sentinela_snapshot' => AgentResult::success('x'),
        ]);
    }
}
