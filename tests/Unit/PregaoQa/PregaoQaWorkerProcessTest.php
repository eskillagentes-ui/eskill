<?php

declare(strict_types=1);

namespace Tests\Unit\PregaoQa;

use App\Services\Pregao\PregaoQaWorkerProcess;
use PHPUnit\Framework\TestCase;

final class PregaoQaWorkerProcessTest extends TestCase
{
    public function testDrainConsumesLargeStderrBeforeReadingProtocolWithoutDeadlock(): void
    {
        self::assertTrue(class_exists(PregaoQaWorkerProcess::class));
        $command = [
            PHP_BINARY,
            '-r',
            'fwrite(STDERR, str_repeat("E", 512 * 1024)); fwrite(STDOUT, "protocol-line\\n");',
        ];
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);

        $lines = [];
        $startedAt = microtime(true);
        $result = PregaoQaWorkerProcess::drain(
            $process,
            $pipes[1],
            $pipes[2],
            static function (string $line) use (&$lines): void {
                $lines[] = $line;
            },
            static function (): void {},
            static fn (): bool => false,
            5
        );

        self::assertLessThan(5.0, microtime(true) - $startedAt);
        self::assertSame(["protocol-line\n"], $lines);
        self::assertSame(0, $result['exit_code']);
        self::assertTrue($result['stderr_present']);
    }

    public function testDrainTerminatesChildWhenShutdownIsRequested(): void
    {
        self::assertTrue(class_exists(PregaoQaWorkerProcess::class));
        $process = proc_open([PHP_BINARY, '-r', 'while (true) { usleep(100000); }'], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $ticks = 0;

        try {
            PregaoQaWorkerProcess::drain(
                $process,
                $pipes[1],
                $pipes[2],
                static function (): void {},
                static function () use (&$ticks): void { $ticks++; },
                static function () use (&$ticks): bool { return $ticks >= 2; },
                5
            );
            self::fail('shutdown deveria abortar a execução');
        } catch (\RuntimeException $exception) {
            self::assertSame('qa_worker_shutdown_requested', $exception->getMessage());
        }

        self::assertFalse(is_resource($process), 'processo filho deve estar fechado após shutdown');
    }

    public function testDrainTerminatesChildOnTimeoutAndClosesProcess(): void
    {
        self::assertTrue(class_exists(PregaoQaWorkerProcess::class));
        $process = proc_open([PHP_BINARY, '-r', 'while (true) { usleep(100000); }'], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);

        try {
            PregaoQaWorkerProcess::drain(
                $process,
                $pipes[1],
                $pipes[2],
                static function (): void {},
                static function (): void {},
                static fn (): bool => false,
                1
            );
            self::fail('timeout deveria abortar a execução');
        } catch (\RuntimeException $exception) {
            self::assertSame('qa_browser_timeout', $exception->getMessage());
        }

        self::assertFalse(is_resource($process), 'processo filho deve estar fechado após timeout');
    }
}
