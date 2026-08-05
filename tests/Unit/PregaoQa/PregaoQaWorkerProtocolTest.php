<?php

declare(strict_types=1);

namespace Tests\Unit\PregaoQa;

use App\Services\Pregao\PregaoQaWorkerProtocol;
use PHPUnit\Framework\TestCase;

final class PregaoQaWorkerProtocolTest extends TestCase
{
    public function testAcceptsExactNodeProtocolAndRejectsExtraOrHostileFields(): void
    {
        $valid = [
            'run_id' => '123e4567-e89b-42d3-a456-426614174000',
            'sequence' => 1,
            'step' => 'dashboard',
            'result' => 'running',
            'screenshot' => 'latest.png',
            'cursor' => ['x' => 10, 'y' => 20],
            'observed_at' => '2026-08-05T12:01:00+00:00',
        ];
        self::assertSame($valid, PregaoQaWorkerProtocol::decode(json_encode($valid, JSON_THROW_ON_ERROR), $valid['run_id'], 0));

        $cases = [];
        $extra = $valid;
        $extra['secret'] = 'x';
        $cases[] = $extra;
        $wrongRun = $valid;
        $wrongRun['run_id'] = '223e4567-e89b-42d3-a456-426614174000';
        $cases[] = $wrongRun;
        $traversal = $valid;
        $traversal['screenshot'] = '../secret.png';
        $cases[] = $traversal;
        $badCursor = $valid;
        $badCursor['cursor'] = ['x' => '10', 'y' => 20];
        $cases[] = $badCursor;
        $badStep = $valid;
        $badStep['step'] = 'delete-account';
        $cases[] = $badStep;
        $badSequence = $valid;
        $badSequence['sequence'] = 0;
        $cases[] = $badSequence;
        $future = $valid;
        $future['observed_at'] = '2099-08-05T12:01:00+00:00';
        $cases[] = $future;

        foreach ($cases as $case) {
            self::assertNull(PregaoQaWorkerProtocol::decode(json_encode($case, JSON_THROW_ON_ERROR), $valid['run_id'], 0));
        }
        self::assertNull(PregaoQaWorkerProtocol::decode('{bad-json', $valid['run_id'], 0));
    }

    public function testWorkerUsesProtocolParserCreatesAndDestroysEphemeralSession(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/bin/pregao-qa-worker.php');
        self::assertIsString($source);
        self::assertStringContainsString('PregaoQaWorkerProtocol::decode', $source);
        self::assertStringContainsString("bin/pregao-qa-browser.mjs", $source);
        self::assertStringContainsString('$sessions->create(', $source);
        self::assertStringContainsString('$sessions->destroy(', $source);
        self::assertStringContainsString('finally', $source);
        self::assertStringContainsString("['PREGAO_QA_SESSION_COOKIE']", $source);
        self::assertStringContainsString("['PREGAO_QA_OUTPUT_DIR']", $source);
        self::assertStringContainsString("['PREGAO_QA_BASE_URL']", $source);
        self::assertStringContainsString('PREGAO_QA_ALLOW_PRODUCTION_READONLY', $source);
        self::assertStringContainsString('stream_set_timeout($pipes[1], 120)', $source);
        self::assertStringContainsString('$runs->releaseActive(', $source);
        self::assertStringNotContainsString("'--session-id'", $source);
        self::assertStringNotContainsString('curl', $source);
    }
}
