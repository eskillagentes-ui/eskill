<?php

declare(strict_types=1);

namespace Tests\Unit\PregaoQa;

use App\Services\Pregao\PregaoQaWorkerProtocol;
use PHPUnit\Framework\TestCase;

final class PregaoQaWorkerProtocolTest extends TestCase
{
    public function testAcceptsOnlyTheExactFiveStepStateMachine(): void
    {
        $runId = '123e4567-e89b-42d3-a456-426614174000';
        $steps = ['dashboard', 'snapshot', 'realtime', 'event_explorer', 'console_http'];
        $previousSequence = 0;
        $previousResult = null;

        foreach ($steps as $index => $step) {
            $sequence = $index + 1;
            $result = $sequence === 5 ? 'passed' : 'running';
            $record = $this->record($runId, $sequence, $step, $result);
            self::assertSame(
                $record,
                PregaoQaWorkerProtocol::decode(
                    json_encode($record, JSON_THROW_ON_ERROR),
                    $runId,
                    $previousSequence,
                    $previousResult
                )
            );
            $previousSequence = $sequence;
            $previousResult = $result;
        }

        self::assertNull($this->decode($this->record($runId, 1, 'console_http', 'passed'), 0, null));
        self::assertNull($this->decode($this->record($runId, 1, 'dashboard', 'passed'), 0, null));
        self::assertNull($this->decode($this->record($runId, 5, 'console_http', 'running'), 4, 'running'));
        self::assertNull($this->decode($this->record($runId, 2, 'realtime', 'running'), 1, 'running'));
    }

    public function testFailedAndBlockedTerminateAtExpectedStepAndRejectLaterEvents(): void
    {
        $runId = '123e4567-e89b-42d3-a456-426614174000';
        $failed = $this->record($runId, 2, 'snapshot', 'failed');
        self::assertSame($failed, $this->decode($failed, 1, 'running'));
        self::assertNull($this->decode($this->record($runId, 3, 'realtime', 'running'), 2, 'failed'));

        $blocked = $this->record($runId, 1, 'dashboard', 'blocked');
        self::assertSame($blocked, $this->decode($blocked, 0, null));
        self::assertNull($this->decode($this->record($runId, 2, 'snapshot', 'running'), 1, 'blocked'));
    }

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
        self::assertStringContainsString("'PREGAO_QA_SESSION_COOKIE' =>", $source);
        self::assertStringContainsString("'PREGAO_QA_OUTPUT_DIR' =>", $source);
        self::assertStringContainsString("'PREGAO_QA_BASE_URL' =>", $source);
        self::assertStringContainsString('PregaoQaWorkerEnvironment::build', $source);
        self::assertStringContainsString('PREGAO_QA_ALLOW_PRODUCTION_READONLY', $source);
        self::assertStringContainsString('stream_set_timeout($pipes[1], 120)', $source);
        self::assertStringContainsString('$runs->releaseActive(', $source);
        self::assertStringNotContainsString("'--session-id'", $source);
        self::assertStringNotContainsString('curl', $source);
    }

    /** @return array<string,mixed> */
    private function record(string $runId, int $sequence, string $step, string $result): array
    {
        return [
            'run_id' => $runId,
            'sequence' => $sequence,
            'step' => $step,
            'result' => $result,
            'screenshot' => null,
            'cursor' => null,
            'observed_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];
    }

    /** @param array<string,mixed> $record @return array<string,mixed>|null */
    private function decode(array $record, int $previousSequence, ?string $previousResult): ?array
    {
        return PregaoQaWorkerProtocol::decode(
            json_encode($record, JSON_THROW_ON_ERROR),
            $record['run_id'],
            $previousSequence,
            $previousResult
        );
    }
}
