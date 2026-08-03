<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agents;

use App\Services\Agents\QaMergeGate;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

/** @covers \App\Services\Agents\QaMergeGate */
final class QaMergeGateTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $originalEnvironment = [];

    /** @var list<string> */
    private const VARIABLES = [
        'QA_GATE_PHP_LINT',
        'QA_GATE_PHPUNIT_AGENTS',
        'QA_GATE_PHPUNIT_UNIT',
        'QA_GATE_PLAYWRIGHT_READONLY',
    ];

    protected function setUp(): void
    {
        foreach (self::VARIABLES as $variable) {
            $this->originalEnvironment[$variable] = getenv($variable);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnvironment as $variable => $value) {
            putenv($value === false ? $variable : $variable . '=' . $value);
        }
    }

    public function testAprovaSomenteComAsQuatroEvidenciasFixasDoProcesso(): void
    {
        $this->setAllPassed();

        (new QaMergeGate())->assertPasses();

        self::assertTrue(true);
    }

    /** @dataProvider invalidEvidenceProvider */
    public function testRejeitaEvidenciaAusenteOuInvalida(string $variable, ?string $value): void
    {
        $this->setAllPassed();
        putenv($value === null ? $variable : $variable . '=' . $value);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('qa_merge_gate_rejected');

        (new QaMergeGate())->assertPasses();
    }

    /** @return iterable<string, array{string, ?string}> */
    public function invalidEvidenceProvider(): iterable
    {
        foreach (self::VARIABLES as $variable) {
            yield $variable . '-missing' => [$variable, null];
            yield $variable . '-invalid' => [$variable, 'success'];
        }
    }

    public function testApiNaoAceitaQaAgentResultadoCallbacksOuContexto(): void
    {
        $method = new ReflectionMethod(QaMergeGate::class, 'assertPasses');
        self::assertSame(0, $method->getNumberOfParameters());

        $source = file_get_contents(__DIR__ . '/../../../../app/Services/Agents/QaMergeGate.php');
        self::assertIsString($source);
        self::assertStringNotContainsString('QaAgent', $source);
        self::assertStringNotContainsString('AgentResult', $source);
        self::assertStringNotContainsString('callable', $source);
    }

    private function setAllPassed(): void
    {
        foreach (self::VARIABLES as $variable) {
            putenv($variable . '=passed');
        }
    }
}
