<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agents;

use App\Services\Agents\CollectorAgent;
use App\Services\Agents\CriadorAgent;
use App\Services\Agents\FinanceiroAgent;
use App\Services\Agents\LegacyReadOnlyAgentAdapter;
use App\Services\Agents\OtimizadorAgent;
use App\Services\Agents\QaAgent;
use App\Services\Agents\SentinelaAgent;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/** Prova estática de que agentes de snapshot não carregam capacidades executáveis. */
final class AgentSnapshotSafetyTest extends TestCase
{
    /** @dataProvider snapshotAgentClasses */
    public function testAgentesNaoAceitamNemArmazenamCallables(string $class): void
    {
        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();

        if ($constructor !== null) {
            $this->assertSame([], $constructor->getParameters(), $class . ' deve ter construtor sem portas');
        }

        foreach ($reflection->getProperties() as $property) {
            $type = $property->getType();
            $this->assertNotSame('callable', $type === null ? null : (string) $type);
            $this->assertNotSame('Closure', $type === null ? null : ltrim((string) $type, '\\'));
        }

        $source = file_get_contents($reflection->getFileName());
        $this->assertIsString($source);
        $this->assertDoesNotMatchRegularExpression('/\\bcallable\\b|\\bClosure\\b|fromCallable|is_callable/', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/\b(?:PDO|Database|MercadoLivre|curl_init|fsockopen|stream_socket_client|exec|system|shell_exec|passthru|proc_open)\b/',
            $source
        );
    }

    /** @dataProvider concreteSnapshotAgents */
    public function testAgentesRejeitamArgumentoExecutavelNoConstrutor(string $class): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new $class(static fn (): array => []);
    }

    /** @return iterable<string, array{class-string}> */
    public function concreteSnapshotAgents(): iterable
    {
        foreach ([
            SentinelaAgent::class,
            CollectorAgent::class,
            FinanceiroAgent::class,
            CriadorAgent::class,
            OtimizadorAgent::class,
            QaAgent::class,
        ] as $class) {
            yield $class => [$class];
        }
    }

    /** @return iterable<string, array{class-string}> */
    public function snapshotAgentClasses(): iterable
    {
        foreach ([
            LegacyReadOnlyAgentAdapter::class,
            SentinelaAgent::class,
            CollectorAgent::class,
            FinanceiroAgent::class,
            CriadorAgent::class,
            OtimizadorAgent::class,
            QaAgent::class,
        ] as $class) {
            yield $class => [$class];
        }
    }
}
