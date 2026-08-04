<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agents;

use PHPUnit\Framework\TestCase;

final class AgentRuntimeCliTest extends TestCase
{
    private string $script;

    protected function setUp(): void
    {
        $this->script = dirname(__DIR__, 4) . '/bin/agent-runtime.php';
    }

    public function testPhpunitXmlNaoRepeteArquivosExplicitos(): void
    {
        $xml = file_get_contents(dirname(__DIR__, 4) . '/phpunit.xml');
        self::assertIsString($xml);
        preg_match_all('/<file>([^<]+)<\/file>/', $xml, $matches);

        self::assertSame(array_values(array_unique($matches[1])), $matches[1]);
    }

    public function testFonteCliExpoeSomenteResumoEProibeWritesOuPayloads(): void
    {
        self::assertFileExists($this->script);
        $source = file_get_contents($this->script);
        self::assertIsString($source);
        foreach (['fwrite(', "'agent'", "'status'", "'reason'", "'stateChanged'", "'emittedOps'"] as $required) {
            self::assertStringContainsString($required, $source);
        }
        foreach ([
            'echo ', 'print_r', 'var_dump', "'payload'", "'resumo'", "'metrics'",
            'file_put_contents', '->post(', '->put(', '->delete(',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $source);
        }
    }

    /** @dataProvider invalidArguments */
    public function testInputInvalidoFalhaAntesDeQualquerAcessoExterno(array $arguments): void
    {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->script);
        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg($argument);
        }
        $lines = [];
        $exitCode = 0;
        exec($command . ' 2>&1', $lines, $exitCode);

        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString('invalid arguments', implode("\n", $lines));
    }

    public function invalidArguments(): iterable
    {
        yield 'account zero' => [[
            '--account-id=0', '--correlation=corr-1', '--environment=local',
        ]];
        yield 'correlation ausente' => [[
            '--account-id=10', '--environment=local',
        ]];
        yield 'environment production typo' => [[
            '--account-id=10', '--correlation=corr-1', '--environment=prod',
        ]];
        yield 'creator nao MLB' => [[
            '--account-id=10', '--correlation=corr-1', '--environment=local', '--creator=123',
        ]];
        yield 'argumento desconhecido' => [[
            '--account-id=10', '--correlation=corr-1', '--environment=local', '--write=true',
        ]];
    }
}
