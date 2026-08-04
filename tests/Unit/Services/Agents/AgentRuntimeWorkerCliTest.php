<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agents;

use PHPUnit\Framework\TestCase;

final class AgentRuntimeWorkerCliTest extends TestCase
{
    private string $script;
    private string $supervisor;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 4);
        $this->script = $root . '/bin/agent-runtime-worker.php';
        $this->supervisor = $root . '/config/supervisor/agent-runtime-worker.conf';
    }

    public function testHelpFuncionaAntesDeAutoloadOuBanco(): void
    {
        self::assertFileExists($this->script);
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->script) . ' --help 2>&1', $lines, $exitCode);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('--loop', implode("\n", $lines));
        self::assertStringContainsString('--interval=SECONDS', implode("\n", $lines));
    }

    /** @dataProvider invalidArguments */
    public function testArgumentoInvalidoFalhaAntesDeAutoloadOuBanco(array $arguments): void
    {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->script);
        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg($argument);
        }
        exec($command . ' 2>&1', $lines, $exitCode);

        self::assertSame(64, $exitCode);
        self::assertStringContainsString('invalid arguments', implode("\n", $lines));
    }

    public function invalidArguments(): iterable
    {
        yield 'sem environment' => [['--once']];
        yield 'interval curto' => [['--loop', '--interval=10', '--environment=production']];
        yield 'tentativas excessivas' => [['--once', '--environment=production', '--max-attempts=4']];
        yield 'once e loop' => [['--once', '--loop', '--environment=production']];
        yield 'flag desconhecida' => [['--once', '--environment=production', '--write']];
    }

    public function testFontePossuiLockHeartbeatAtomicoESomenteRuntimeReadOnly(): void
    {
        $source = file_get_contents($this->script);
        self::assertIsString($source);
        foreach ([
            'LOCK_EX | LOCK_NB', 'AgentRuntimeAccountSource', 'AgentRuntimeExecutor',
            'AgentRuntimeWorker', 'agent-runtime-heartbeat.json', 'rename(',
        ] as $required) {
            self::assertStringContainsString($required, $source);
        }
        foreach (['MercadoLivreClient', '->post(', '->put(', '->delete(', 'mlWriteAutomation'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $source);
        }
    }

    public function testSupervisorMantemWorkerMonitorComRestartELogsLimitados(): void
    {
        self::assertFileExists($this->supervisor);
        $source = file_get_contents($this->supervisor);
        self::assertIsString($source);
        foreach ([
            '--loop', '--interval=300', '--environment=production', '--max-attempts=2',
            'autostart=true', 'autorestart=true', 'user=eskill',
            'stdout_logfile_maxbytes=', 'stdout_logfile_backups=', 'stopasgroup=true',
        ] as $required) {
            self::assertStringContainsString($required, $source);
        }
    }
}
