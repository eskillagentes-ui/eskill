<?php

declare(strict_types=1);

namespace Tests\Unit\PregaoQa;

use PHPUnit\Framework\TestCase;

final class PregaoQaWorkerRuntimeContractTest extends TestCase
{
    public function testWorkerUsesConcurrentDrainRenewalAndFailClosedSignalHandlers(): void
    {
        $root = dirname(__DIR__, 3);
        $source = file_get_contents($root . '/bin/pregao-qa-worker.php');
        self::assertIsString($source);

        foreach ([
            'use App\\Services\\Pregao\\PregaoQaWorkerProcess;',
            'use App\\Services\\Pregao\\PregaoQaWorkerSignals;',
            'PregaoQaWorkerProcess::drain(',
            '$signals->install();',
            '$signals->track($process);',
            '$runs->renew(',
            'qa_worker_shutdown_requested',
            'qa_worker_lock_lost',
            '$preservePending = $shutdownRequested && $terminalResult === null;',
            'PregaoQaWorkerProcess::terminate($process);',
            "'PREGAO_QA_PRIVATE_ROOT'",
        ] as $required) {
            self::assertStringContainsString($required, $source);
        }

        self::assertStringNotContainsString('stream_set_blocking($pipes[1], true)', $source);
        self::assertStringNotContainsString('stream_get_contents($pipes[2]', $source);
        self::assertStringContainsString('if (!$preservePending && $terminalResult === null)', $source);
        self::assertStringContainsString('if (!$preservePending && $terminalResult !== null)', $source);
    }
}
