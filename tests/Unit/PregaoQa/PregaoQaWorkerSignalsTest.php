<?php

declare(strict_types=1);

namespace Tests\Unit\PregaoQa;

use App\Services\Pregao\PregaoQaWorkerSignals;
use PHPUnit\Framework\TestCase;

final class PregaoQaWorkerSignalsTest extends TestCase
{
    /** @dataProvider terminationSignals */
    public function testInstalledSignalTerminatesTrackedChildAndRecordsShutdown(int $signal): void
    {
        self::assertTrue(class_exists(PregaoQaWorkerSignals::class));
        self::assertTrue(function_exists('posix_kill'));
        $process = proc_open([PHP_BINARY, '-r', 'while (true) { usleep(100000); }'], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);

        try {
            $signals = new PregaoQaWorkerSignals();
            $signals->install();
            $signals->track($process);
            self::assertTrue(posix_kill(getmypid(), $signal));
            usleep(100000);

            self::assertTrue($signals->isRequested());
            $status = proc_get_status($process);
            self::assertFalse($status['running'] ?? true);
        } finally {
            pcntl_signal(SIGTERM, SIG_DFL);
            pcntl_signal(SIGINT, SIG_DFL);
            foreach ([$pipes[1] ?? null, $pipes[2] ?? null] as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            if (is_resource($process)) {
                proc_close($process);
            }
        }
    }

    /** @return array<string,array{int}> */
    public function terminationSignals(): array
    {
        return [
            'SIGTERM' => [SIGTERM],
            'SIGINT' => [SIGINT],
        ];
    }
}
